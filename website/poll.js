// In-page announcement polling
(function(){
  let last = 0;
  async function poll(){
    try {
      const r = await fetch('/NoteSphere/api_latest_announcement.php?last='+last);
      const d = await r.json();
      if (d.new && d.latest > last && last !== 0) {
        if (Notification.permission === 'granted') {
          new Notification('Bagong Announcement', { body: d.title });
        }
      }
      last = d.latest || last;
    } catch(e){}
  }
  if ('Notification' in window) Notification.requestPermission();
  setInterval(poll, 30000);
  poll();
})();
