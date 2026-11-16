/*
 * Dar Mutiara - Video player fix
 *
 * Normalizes supported YouTube/Vimeo watch URLs into provider embed URLs.
 * Keeps the existing notes, discussion, and confirmation-form behaviors.
 */

function darMutiaraEmbedUrl(rawUrl) {
    try {
        const url = new URL(rawUrl, window.location.origin);
        const host = url.hostname.toLowerCase().replace(/^www\./, '');
        const path = url.pathname;

        // YouTube: watch, short links, shorts, live, and existing embed URLs.
        if (
            host === 'youtube.com' ||
            host === 'youtu.be' ||
            host === 'youtube-nocookie.com'
        ) {
            let videoId = null;

            if (host === 'youtu.be') {
                videoId = path.split('/').filter(Boolean)[0] || null;
            } else if (path === '/watch') {
                videoId = url.searchParams.get('v');
            } else {
                const match = path.match(/^\/(?:embed|shorts|live)\/([A-Za-z0-9_-]{11})(?:\/|$)/);
                videoId = match ? match[1] : null;
            }

            if (!videoId || !/^[A-Za-z0-9_-]{11}$/.test(videoId)) {
                return null;
            }

            return `https://www.youtube-nocookie.com/embed/${videoId}?rel=0&playsinline=1`;
        }

        // Vimeo: regular video URLs and player URLs.
        if (host === 'vimeo.com' || host === 'player.vimeo.com') {
            const match = path.match(/\/(?:video\/)?(\d+)(?:\/|$)/);
            if (!match) {
                return null;
            }

            return `https://player.vimeo.com/video/${match[1]}`;
        }

        return null;
    } catch {
        return null;
    }
}

document.querySelectorAll('form[data-confirm]').forEach(form => {
    form.addEventListener('submit', event => {
        if (!confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });
});

// Video player: normalize URLs before assigning them to the iframe.
document.querySelectorAll('[data-video]').forEach(element => {
    const button = element.querySelector('button');
    if (!button) return;

    button.addEventListener('click', () => {
        const embedUrl = darMutiaraEmbedUrl(element.dataset.video);

        if (!embedUrl) {
            const message = document.createElement('p');
            message.className = 'video-error';
            message.textContent = 'Tautan video tidak dikenali. Gunakan tautan YouTube atau Vimeo yang valid.';
            element.replaceChildren(message);
            return;
        }

        const frame = document.createElement('iframe');
        frame.src = embedUrl;
        frame.title = 'Video pembelajaran Dar Mutiara';
        frame.loading = 'eager';
        frame.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
        frame.allowFullscreen = true;
        frame.referrerPolicy = 'strict-origin-when-cross-origin';

        element.replaceChildren(frame);
    });
});

const tabs = document.querySelector('[data-learning-tabs]');

if (tabs) {
    let version = 0;
    let noteLoaded = false;
    let nextPage = null;

    const moduleId = tabs.dataset.module;
    const status = tabs.querySelector('[data-note-status]');
    const note = tabs.querySelector('[data-note]');

    async function api(url, options = {}) {
        const response = await fetch(url, {
            ...options,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                ...options.headers
            }
        });

        if (!response.ok) {
            let error;
            try {
                error = await response.json();
            } catch {
                // The response may not contain JSON.
            }

            throw new Error(
                error?.message ||
                (response.status === 419
                    ? 'Sesi berakhir. Muat ulang halaman.'
                    : 'Tidak dapat memuat data.')
            );
        }

        return response.json();
    }

    async function posts(url, append = false) {
        const result = await api(url);
        const target = tabs.querySelector('[data-posts]');

        if (!append) {
            target.replaceChildren();
        }

        result.data.forEach(post => {
            const box = document.createElement('div');
            box.className = 'submission';

            const name = document.createElement('strong');
            name.textContent = post.name;

            const body = document.createElement('p');
            body.textContent = post.body;

            box.append(name, body);
            target.append(box);
        });

        if (!result.data.length && !append) {
            target.textContent = 'Belum ada diskusi.\nMulai dengan pertanyaan Anda.';
        }

        nextPage = result.next_page_url;
        tabs.querySelector('[data-more]').hidden = !nextPage;
    }

    tabs.querySelectorAll('[data-tab]').forEach(button => {
        button.addEventListener('click', async () => {
            const name = button.dataset.tab;

            tabs.querySelectorAll('[data-pane]').forEach(pane => {
                pane.hidden = pane.dataset.pane !== name;
            });

            tabs.querySelectorAll('[data-tab]').forEach(tabButton => {
                tabButton.setAttribute('aria-selected', tabButton === button ? 'true' : 'false');
            });

            try {
                if (name === 'notes' && !noteLoaded) {
                    const result = await api(`/modul/${moduleId}/catatan`);
                    note.value = result.body;
                    version = result.version;
                    noteLoaded = true;
                }

                if (name === 'discussion') {
                    await posts(`/modul/${moduleId}/diskusi`);
                }
            } catch (error) {
                if (name === 'notes') {
                    status.textContent = error.message;
                } else {
                    tabs.querySelector('[data-posts]').textContent = error.message;
                }
            }
        });
    });

    tabs.querySelector('[data-save-note]').addEventListener('click', async event => {
        if (!noteLoaded) {
            status.textContent = 'Muat ulang tab catatan sebelum menyimpan.';
            return;
        }

        event.target.disabled = true;

        try {
            const result = await api(`/modul/${moduleId}/catatan`, {
                method: 'PUT',
                body: JSON.stringify({
                    body: note.value,
                    version
                })
            });

            version = result.version;
            status.textContent = ' Tersimpan.';
        } catch (error) {
            status.textContent = ` ${error.message}`;
        } finally {
            event.target.disabled = false;
        }
    });

    tabs.querySelector('[data-more]').addEventListener('click', async () => {
        try {
            if (nextPage) {
                await posts(nextPage, true);
            }
        } catch (error) {
            tabs.querySelector('[data-posts]').textContent = error.message;
        }
    });
}
