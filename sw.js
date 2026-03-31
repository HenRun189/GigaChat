self.addEventListener('install', event => {
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(self.clients.claim());
});

/* =========================
   PUSH
========================= */
self.addEventListener('push', event => {
  let data = {};
  try {
    if (event.data) data = event.data.json();
  } catch (e) {}

  const chatId = Number(data.chatId || data.chat || 0);
  const title = data.title || 'Neue Nachricht';

  const options = {
    body: data.body || '',
    icon: 'logo.png',
    badge: 'logo.png',
    tag: 'chat_' + chatId,
    renotify: false,

    data: {
      chat: chatId,
      type: data.type || 'user'
    },

    actions: [
      {
        action: 'reply',
        title: 'Antworten',
        type: 'text',
        placeholder: 'Antwort schreiben…'
      }
    ]
  };

  event.waitUntil(
    self.registration.showNotification(title, options)
  );
});

/* =========================
   NOTIFICATION CLICK
========================= */
self.addEventListener('notificationclick', event => {
  const action = event.action;
  const data = event.notification.data || {};
  const reply = event.reply;

  event.notification.close();

  /* ✍️ INLINE REPLY (safe) */
  if (action === 'reply' && typeof reply === 'string' && reply.trim()) {
    event.waitUntil(
      fetch('/push-reply.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          chat: data.chat,
          type: data.type,
          text: reply
        })
      }).catch(() => {})
    );
    return;
  }

  /* 📲 CHAT ÖFFNEN */
  event.waitUntil(
    self.clients.matchAll({
      type: 'window',
      includeUncontrolled: true
    }).then(clients => {

      if (clients.length > 0) {
        const client = clients[0];

        client.postMessage({
          type: 'OPEN_CHAT',
          chatId: data.chat,
          chatType: data.type || 'user'
        });

        return client.focus();
      }

      let url = '/?from_push=1';
      if (data.type === 'user')  url += '&user_id=' + data.chat;
      if (data.type === 'group') url += '&group_id=' + data.chat;

      return self.clients.openWindow(url);
    })
  );
});
