const fs = require('fs');
const path = require('path');
const express = require('express');
const webpush = require('web-push');
const cors = require('cors');

const app = express();
app.use(cors());
app.use(express.json());

const dataDir = __dirname;
const vapidFile = path.join(dataDir, 'vapid.json');
const subscriptionsFile = path.join(dataDir, 'subscriptions.json');

function readJson(file, fallback) {
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (_) {
    return fallback;
  }
}

function writeJson(file, value) {
  fs.writeFileSync(file, JSON.stringify(value, null, 2));
}

let vapidKeys = readJson(vapidFile, null);
if (!vapidKeys || !vapidKeys.publicKey || !vapidKeys.privateKey) {
  vapidKeys = webpush.generateVAPIDKeys();
  writeJson(vapidFile, vapidKeys);
}
webpush.setVapidDetails('mailto:admin@notesphere.local', vapidKeys.publicKey, vapidKeys.privateKey);

function loadSubscriptions() {
  const value = readJson(subscriptionsFile, []);
  return Array.isArray(value) ? value : [];
}

function saveSubscriptions(subscriptions) {
  writeJson(subscriptionsFile, subscriptions);
}

app.get('/vapid-public', (req, res) => {
  res.json({ publicKey: vapidKeys.publicKey });
});

app.get('/status', (req, res) => {
  res.json({ ok: true, subscriptions: loadSubscriptions().length });
});

app.post('/subscribe', (req, res) => {
  const subscription = req.body && req.body.subscription ? req.body.subscription : req.body;
  if (!subscription || !subscription.endpoint) {
    return res.status(400).json({ ok: false, error: 'Invalid subscription.' });
  }

  const subscriptions = loadSubscriptions();
  const existingIndex = subscriptions.findIndex(item => item && item.endpoint === subscription.endpoint);
  const record = {
    endpoint: subscription.endpoint,
    keys: subscription.keys || {},
    user: req.body && req.body.user ? req.body.user : null,
    createdAt: new Date().toISOString(),
    updatedAt: new Date().toISOString()
  };

  if (existingIndex >= 0) {
    subscriptions[existingIndex] = { ...subscriptions[existingIndex], ...record, createdAt: subscriptions[existingIndex].createdAt || record.createdAt };
  } else {
    subscriptions.push(record);
  }

  saveSubscriptions(subscriptions);
  res.json({ ok: true, subscriptions: subscriptions.length });
});

app.post('/unsubscribe', (req, res) => {
  const endpoint = req.body && req.body.endpoint ? req.body.endpoint : null;
  if (!endpoint) return res.status(400).json({ ok: false, error: 'Endpoint is required.' });

  const subscriptions = loadSubscriptions().filter(item => !item || item.endpoint !== endpoint);
  saveSubscriptions(subscriptions);
  res.json({ ok: true, subscriptions: subscriptions.length });
});

app.post('/notify', async (req, res) => {
  const body = req.body || {};
  const targetUserIds = Array.isArray(body.user_ids) ? body.user_ids.map(value => String(value)) : null;
  const notificationPayload = { ...body };
  delete notificationPayload.user_ids;
  const payload = JSON.stringify(Object.keys(notificationPayload).length ? notificationPayload : { title: 'NoteSphere', body: 'New update' });

  const subscriptions = loadSubscriptions();
  const recipients = targetUserIds
    ? subscriptions.filter(item => item && targetUserIds.includes(String(item.user)))
    : subscriptions;

  const results = await Promise.allSettled(recipients.map(async (subscription) => {
    try {
      await webpush.sendNotification(subscription, payload);
      return { ok: true, endpoint: subscription.endpoint };
    } catch (error) {
      if (error && (error.statusCode === 404 || error.statusCode === 410)) {
        const remaining = loadSubscriptions().filter(item => item && item.endpoint !== subscription.endpoint);
        saveSubscriptions(remaining);
      }
      throw error;
    }
  }));

  const sent = results.filter(result => result.status === 'fulfilled').length;
  res.json({ ok: true, sent, total: recipients.length });
});

app.listen(4000, () => console.log('Push server on 4000'));
