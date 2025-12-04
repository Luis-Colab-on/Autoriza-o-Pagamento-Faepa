(function() {
  if (!window.faepaChatbox || !faepaChatbox.nonce) { return; }

  const state = {
    contacts: [],
    currentContact: null,
    currentThread: 0,
    messages: [],
    polling: null,
    searchTimer: null,
    overlayOpen: false,
    autoScroll: true
  };

  const qs = (id) => document.getElementById(id);
  const root = qs('faepaChatboxRoot');
  if (!root) { return; }

  const isDesk = !!faepaChatbox.isFinanceDesk;
  const btn = qs('faepaChatboxButton');
  const badge = qs('faepaChatboxBadge');
  const overlay = qs('faepaChatboxOverlay');
  const modalClose = qs('faepaChatboxClose');
  const contactsEl = qs('faepaChatContacts');
  const searchEl = qs('faepaChatSearch');
  const headerEl = qs('faepaChatHeader');
  const msgsEl = qs('faepaChatMessages');
  const formEl = qs('faepaChatForm');
  const inputEl = qs('faepaChatInput');
  const fileEl = qs('faepaChatFile');
  const defaultEmptyMsg = msgsEl ? (msgsEl.dataset.empty || faepaChatbox.strings.emptyMsg) : 'Nenhuma mensagem ainda.';

  const request = (action, data, isMultipart = false) => {
    const body = isMultipart ? data : new URLSearchParams(data);
    return fetch(faepaChatbox.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: isMultipart ? {} : { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body
    }).then(res => res.json());
  };

  const toggleOverlay = (show) => {
    state.overlayOpen = show;
    overlay.hidden = !show;
    if (show) {
      loadContacts();
      loadUnread();
      startPolling();
    } else {
      stopPolling();
    }
  };

  const startPolling = () => {
    if (state.polling) { return; }
    state.polling = setInterval(() => {
      loadUnread();
      if (state.currentContact) {
        loadMessages(state.currentContact);
      }
    }, 10000);
  };

  const stopPolling = () => {
    if (state.polling) {
      clearInterval(state.polling);
      state.polling = null;
    }
  };

  const renderBadge = (count) => {
    if (!badge) { return; }
    if (!count) {
      badge.hidden = true;
      return;
    }
    badge.hidden = false;
    badge.textContent = count > 99 ? '+99' : count;
  };

  const renderContacts = () => {
    contactsEl.innerHTML = '';
    if (!state.contacts.length) {
      const p = document.createElement('p');
      p.className = 'faepa-chat-empty';
      p.textContent = faepaChatbox.strings.emptyList;
      contactsEl.appendChild(p);
      return;
    }

    state.contacts.forEach(c => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'faepa-chat-contact';
      if (state.currentContact && state.currentContact.user_id === c.user_id) {
        btn.classList.add('is-active');
      }
      btn.dataset.userId = c.user_id;
      btn.textContent = c.name || c.email;
      if (c.unread && c.unread > 0) {
        const badge = document.createElement('span');
        badge.className = 'faepa-chat-contact__badge';
        badge.textContent = c.unread > 99 ? '+99' : c.unread;
        btn.appendChild(badge);
      }
      btn.addEventListener('click', () => {
        if (state.currentContact && state.currentContact.user_id === c.user_id) {
          closeConversation();
          renderContacts();
        } else {
          state.currentContact = c;
          loadMessages(c, { resetScroll: true });
        }
      });
      contactsEl.appendChild(btn);
    });
  };

  const renderMessages = () => {
    msgsEl.innerHTML = '';
    if (!state.messages.length) {
      const p = document.createElement('p');
      p.className = 'faepa-chat-empty';
      p.textContent = msgsEl.dataset.empty || faepaChatbox.strings.emptyMsg;
      msgsEl.appendChild(p);
      return;
    }
    state.messages.forEach(m => {
      const item = document.createElement('div');
      item.className = 'faepa-chat-message';
      if (m.is_own) { item.classList.add('is-own'); }
      const meta = document.createElement('div');
      meta.className = 'faepa-chat-message__meta';
      meta.textContent = m.created_at;
      const body = document.createElement('div');
      body.className = 'faepa-chat-message__body';
      if (m.text) {
        const text = document.createElement('p');
        text.textContent = m.text;
        body.appendChild(text);
      }
      if (m.attachment) {
        const imgLink = document.createElement('a');
        imgLink.href = m.attachment;
        imgLink.target = '_blank';
        imgLink.rel = 'noopener noreferrer';
        const img = document.createElement('img');
        img.src = m.attachment;
        img.alt = 'Imagem';
        img.className = 'faepa-chat-message__img';
        imgLink.appendChild(img);
      body.appendChild(imgLink);
      }
      item.appendChild(body);
      item.appendChild(meta);
      msgsEl.appendChild(item);
    });
    if (state.autoScroll) {
      msgsEl.scrollTop = msgsEl.scrollHeight;
    }
  };

  const loadContacts = () => {
    const search = searchEl.value || '';
    request('faepa_chat_contacts', {
      action: 'faepa_chat_contacts',
      _ajax_nonce: faepaChatbox.nonce,
      search,
      is_desk: isDesk ? 1 : 0
    }).then(res => {
      if (!res || !res.success) { return; }
      state.contacts = res.data.contacts || [];
      renderContacts();
    });
  };

  const loadMessages = (contact, options = {}) => {
    if (!contact) { return; }
    if (options.resetScroll) {
      state.autoScroll = true;
    }
    headerEl.textContent = contact.name || contact.email || 'Contato';
    msgsEl.dataset.empty = defaultEmptyMsg;
    const data = {
      action: 'faepa_chat_messages',
      _ajax_nonce: faepaChatbox.nonce,
      thread_id: contact.thread_id || 0,
      contact_id: contact.user_id,
      is_desk: isDesk ? 1 : 0
    };
    request('faepa_chat_messages', data).then(res => {
      if (!res || !res.success) { return; }
      state.currentThread = res.data.thread_id;
      state.messages = res.data.messages || [];
      // Atualiza thread_id e limpa badge
      state.contacts = state.contacts.map(c => {
        if (c.user_id === contact.user_id) {
          c.thread_id = res.data.thread_id;
          c.unread = 0;
        }
        return c;
      });
      renderContacts();
      renderMessages();
    });
  };

  const closeConversation = () => {
    state.currentContact = null;
    state.currentThread = 0;
    state.messages = [];
    headerEl.textContent = 'Selecione um contato';
    msgsEl.dataset.empty = 'Selecione um contato';
    state.autoScroll = true;
    renderMessages();
  };

  const handleScroll = () => {
    const nearBottom = (msgsEl.scrollHeight - (msgsEl.scrollTop + msgsEl.clientHeight)) <= 30;
    state.autoScroll = nearBottom;
  };

  const sendMessage = (evt) => {
    evt.preventDefault();
    if (!state.currentContact) { return; }
    const text = inputEl.value.trim();
    const file = fileEl.files[0];

    if (!text && !file) { return; }

    const form = new FormData();
    form.append('action', 'faepa_chat_send');
    form.append('_ajax_nonce', faepaChatbox.nonce);
    form.append('thread_id', state.currentThread || 0);
    form.append('contact_id', state.currentContact.user_id);
    form.append('message', text);
    if (isDesk) {
      form.append('is_desk', 1);
    }
    if (file) {
      form.append('attachment', file);
    }

    request('faepa_chat_send', form, true).then(res => {
      if (!res || !res.success) { return; }
      inputEl.value = '';
      fileEl.value = '';
      state.currentThread = res.data.thread_id;
      state.messages.push(res.data.message);
      renderMessages();
      loadContacts();
    });
  };

  const loadUnread = () => {
    request('faepa_chat_unread_count', {
      action: 'faepa_chat_unread_count',
      _ajax_nonce: faepaChatbox.nonce,
      is_desk: isDesk ? 1 : 0
    }).then(res => {
      if (!res || !res.success) { return; }
      renderBadge(res.data.unread || 0);
    });
  };

  const debounceSearch = () => {
    clearTimeout(state.searchTimer);
    state.searchTimer = setTimeout(() => {
      loadContacts();
    }, 300);
  };

  // Eventos
  btn.addEventListener('click', () => toggleOverlay(true));
  modalClose.addEventListener('click', () => toggleOverlay(false));
  overlay.addEventListener('click', (e) => {
    if (e.target === overlay) {
      toggleOverlay(false);
    }
  });
  searchEl.addEventListener('input', debounceSearch);
  formEl.addEventListener('submit', sendMessage);
  msgsEl.addEventListener('scroll', handleScroll);

  // Inicial
  if (overlay) {
    overlay.hidden = true; // garante fechado ao carregar
  }
  loadUnread();
})();
