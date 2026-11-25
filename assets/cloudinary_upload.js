/**
 * NoteSphere - direct-to-Cloudinary uploads
 *
 * Vercel Functions reject request bodies over ~4.5 MB and cannot persist local
 * files. This helper uploads file inputs straight to Cloudinary from the
 * browser, then submits only small hidden URL fields to PHP.
 *
 * When Cloudinary is not configured the helper does nothing, so local XAMPP
 * development keeps using normal $_FILES uploads.
 */
(function () {
    'use strict';

    var scriptUrl = document.currentScript ? document.currentScript.src : '';
    var configUrl = scriptUrl
        ? new URL('../api/cloudinary_config.php', scriptUrl).href
        : '/api/cloudinary_config.php';

    var configPromise = null;
    var busy = false;

    function getConfig() {
        if (!configPromise) {
            configPromise = fetch(configUrl, { credentials: 'same-origin' })
                .then(function (response) { return response.ok ? response.json() : null; })
                .catch(function () { return null; });
        }

        return configPromise;
    }

    function folderForPage() {
        var path = window.location.pathname.replace(/\/[^/]*$/, '');
        var segment = path.split('/').filter(Boolean).pop() || 'uploads';
        return segment.replace(/[^A-Za-z0-9_-]/g, '') || 'uploads';
    }

    function showBusy(text) {
        var el = document.getElementById('ns-upload-busy');

        if (!el) {
            el = document.createElement('div');
            el.id = 'ns-upload-busy';
            el.style.cssText = 'position:fixed;inset:auto 18px 18px auto;z-index:2147483647;' +
                'padding:13px 18px;border-radius:12px;background:#111;color:#39FF14;' +
                'border:1px solid #39FF14;font:600 13px/1.4 system-ui,sans-serif;' +
                'box-shadow:0 12px 30px rgba(0,0,0,.35);max-width:min(360px,calc(100vw - 36px));';
            document.body.appendChild(el);
        }

        el.textContent = text;
        el.style.display = 'block';
    }

    function hideBusy() {
        var el = document.getElementById('ns-upload-busy');

        if (el) {
            el.style.display = 'none';
        }
    }

    function uploadFile(file, config) {
        var form = new FormData();
        form.append('file', file, file.name || 'upload');
        form.append('upload_preset', config.uploadPreset);

        if (config.folder) {
            form.append('folder', config.folder + '/' + folderForPage());
        }

        form.append('use_filename', 'true');
        form.append('unique_filename', 'true');

        var endpoint = 'https://api.cloudinary.com/v1_1/'
            + encodeURIComponent(config.cloudName)
            + '/auto/upload';

        return fetch(endpoint, { method: 'POST', body: form })
            .then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok || !data.secure_url) {
                        var message = data && data.error && data.error.message
                            ? data.error.message
                            : 'Cloud upload failed.';
                        throw new Error(message);
                    }

                    return data.secure_url;
                });
            });
    }

    function baseFieldName(name) {
        return String(name || 'file').replace(/\[\]$/, '');
    }

    function appendHidden(container, name, value) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;

        if (container && typeof container.appendChild === 'function') {
            container.appendChild(input);
        }

        return input;
    }

    /**
     * Upload every file input in a form and replace it with hidden URL fields.
     */
    function prepareForm(form, config) {
        var inputs = Array.prototype.slice.call(
            form.querySelectorAll('input[type="file"]')
        ).filter(function (input) {
            return input.files && input.files.length > 0;
        });

        if (!inputs.length) {
            return Promise.resolve(false);
        }

        var queue = [];
        var total = 0;

        inputs.forEach(function (input) {
            total += input.files.length;
        });

        var done = 0;

        inputs.forEach(function (input) {
            var multiple = input.multiple || /\[\]$/.test(input.name || '');
            var base = baseFieldName(input.name);

            Array.prototype.forEach.call(input.files, function (file) {
                queue.push(function () {
                    return uploadFile(file, config).then(function (url) {
                        done += 1;
                        showBusy('Uploading file ' + done + ' of ' + total + '...');

                        if (multiple) {
                            appendHidden(form, base + '__urls[]', url);
                            appendHidden(form, base + '__names[]', file.name || 'file');
                            appendHidden(form, base + '__sizes[]', String(file.size || 0));
                            appendHidden(form, base + '__types[]', file.type || '');
                        } else {
                            appendHidden(form, base + '__url', url);
                            appendHidden(form, base + '__name', file.name || 'file');
                            appendHidden(form, base + '__size', String(file.size || 0));
                            appendHidden(form, base + '__type', file.type || '');
                        }
                    });
                });
            });

            // Prevent the (now uploaded) files from being sent through Vercel.
            // Also drop the HTML "required" flag so the browser does not block
            // the second submission after the file input has been cleared.
            input.required = false;
            input.removeAttribute('required');
            input.value = '';
        });

        return queue.reduce(function (promise, task) {
            return promise.then(task);
        }, Promise.resolve()).then(function () {
            return true;
        });
    }

    /**
     * Convert a FormData instance used by fetch/XHR.
     */
    function prepareFormData(formData, config) {
        var files = [];
        var entries = [];

        formData.forEach(function (value, key) {
            if (typeof File !== 'undefined' && value instanceof File) {
                files.push({ key: key, file: value });
            } else {
                entries.push([key, value]);
            }
        });

        if (!files.length) {
            return Promise.resolve(formData);
        }

        var total = files.length;
        var done = 0;

        return files.reduce(function (promise, item) {
            return promise.then(function () {
                return uploadFile(item.file, config).then(function (url) {
                    done += 1;
                    showBusy('Uploading file ' + done + ' of ' + total + '...');
                    var base = baseFieldName(item.key);
                    var multiple = /\[\]$/.test(item.key);

                    if (multiple) {
                        entries.push([base + '__urls[]', url]);
                        entries.push([base + '__names[]', item.file.name || 'file']);
                        entries.push([base + '__sizes[]', String(item.file.size || 0)]);
                        entries.push([base + '__types[]', item.file.type || '']);
                    } else {
                        entries.push([base + '__url', url]);
                        entries.push([base + '__name', item.file.name || 'file']);
                        entries.push([base + '__size', String(item.file.size || 0)]);
                        entries.push([base + '__type', item.file.type || '']);
                    }
                });
            });
        }, Promise.resolve()).then(function () {
            var next = new FormData();
            entries.forEach(function (pair) {
                next.append(pair[0], pair[1]);
            });

            return next;
        });
    }

    function install() {
        getConfig().then(function (config) {
            if (!config || !config.enabled) {
                return;
            }

            window.NOTESPHERE_CLOUDINARY = config;

            // 1. Normal HTML form submissions.
            document.addEventListener('submit', function (event) {
                var form = event.target;

                if (!(form instanceof HTMLFormElement) || busy || form.dataset.nsCloudReady === '1') {
                    return;
                }

                var hasFiles = Array.prototype.some.call(
                    form.querySelectorAll('input[type="file"]'),
                    function (input) { return input.files && input.files.length > 0; }
                );

                if (!hasFiles) {
                    return;
                }

                event.preventDefault();
                busy = true;
                showBusy('Preparing upload...');

                prepareForm(form, config)
                    .then(function () {
                        form.dataset.nsCloudReady = '1';
                        busy = false;
                        hideBusy();

                        try {
                            if (typeof form.requestSubmit === 'function') {
                                form.requestSubmit();
                            } else {
                                HTMLFormElement.prototype.submit.call(form);
                            }
                        } catch (submitError) {
                            HTMLFormElement.prototype.submit.call(form);
                        }
                    })
                    .catch(function (error) {
                        busy = false;
                        hideBusy();
                        window.alert(error.message || 'The file upload failed. Please try again.');
                    });
            }, true);

            // 2. fetch(FormData) used by the chat and AJAX pages.
            var nativeFetch = window.fetch;

            if (typeof nativeFetch === 'function') {
                window.fetch = function (input, init) {
                    if (init && typeof FormData !== 'undefined' && init.body instanceof FormData) {
                        return getConfig().then(function (cfg) {
                            if (!cfg || !cfg.enabled) {
                                return nativeFetch.call(window, input, init);
                            }

                            return prepareFormData(init.body, cfg).then(function (body) {
                                var nextInit = Object.assign({}, init, { body: body });
                                return nativeFetch.call(window, input, nextInit);
                            }).finally(hideBusy);
                        });
                    }

                    return nativeFetch.call(window, input, init);
                };
            }

            // 3. XMLHttpRequest(FormData) fallback.
            if (window.XMLHttpRequest && window.XMLHttpRequest.prototype.send) {
                var nativeSend = window.XMLHttpRequest.prototype.send;

                window.XMLHttpRequest.prototype.send = function (body) {
                    var xhr = this;

                    if (typeof FormData !== 'undefined' && body instanceof FormData) {
                        getConfig().then(function (cfg) {
                            if (!cfg || !cfg.enabled) {
                                nativeSend.call(xhr, body);
                                return;
                            }

                            prepareFormData(body, cfg).then(function (nextBody) {
                                nativeSend.call(xhr, nextBody);
                            }).catch(function (error) {
                                hideBusy();
                                xhr.dispatchEvent(new ProgressEvent('error'));
                                window.alert(error.message || 'The file upload failed. Please try again.');
                            });
                        });

                        return;
                    }

                    nativeSend.call(xhr, body);
                };
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', install);
    } else {
        install();
    }

    window.NSUpload = {
        config: getConfig,
        uploadFile: function (file) {
            return getConfig().then(function (config) {
                if (!config || !config.enabled) {
                    throw new Error('Cloudinary is not configured.');
                }

                return uploadFile(file, config);
            });
        }
    };
})();
