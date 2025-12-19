(function() {
  const root = document.getElementById('faepaChatboxRoot');
  if (!root) { return; }

  const loggedIn = !!(document.body && document.body.classList.contains('logged-in'));
  const isLoginCard = !!document.querySelector('.apf-login-card');
  const forceHide = !!window.faepaChatboxForceHide;
  if (!loggedIn || isLoginCard || forceHide) {
    root.remove();
    return;
  }

  if (!window.faepaChatbox || !faepaChatbox.nonce) { return; }

  const state = {
    contacts: [],
    currentContact: null,
    currentThread: 0,
    messages: [],
    polling: null,
    searchTimer: null,
    overlayOpen: false,
    autoScroll: true,
    mobileView: 'list',
  };

  const qs = (id) => document.getElementById(id);

  const isDesk = !!faepaChatbox.isFinanceDesk;
  const portalReadyFlag = (typeof window.faepaChatboxPortalReady !== 'undefined') ? !!window.faepaChatboxPortalReady : null;
  const portalContextFlag = (window.faepaChatboxPortalContext || '').trim();
  const MAX_FILE_BYTES = 5 * 1024 * 1024; // 5MB
  const resolvedContext = (() => {
    if (portalReadyFlag === false) {
      return '';
    }
    let ctx = (faepaChatbox.context || '').trim();
    if (portalReadyFlag === true && portalContextFlag) {
      return portalContextFlag;
    }
    if (ctx) { return ctx; }
    if (document.querySelector('.apf-coord-portal')) { return 'coordenador'; }
    if (document.querySelector('#apfFaepaPortal')) { return 'faepa'; }
    if (document.querySelector('.apf-portal')) { return 'colaborador'; }
    return '';
  })();
  const blockedByFlag = (portalReadyFlag === false);
  if ((!isDesk && (!resolvedContext || blockedByFlag)) || isLoginCard) {
    root.remove();
    return;
  }
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
  const modalEl = overlay ? overlay.querySelector('.faepa-chat-modal') : null;
  const backEl = qs('faepaChatBack');
  const mobileMq = window.matchMedia('(max-width: 1000px)');
  const defaultEmptyMsg = msgsEl ? (msgsEl.dataset.empty || faepaChatbox.strings.emptyMsg) : 'Nenhuma mensagem ainda.';
  const contactKeyOf = (contact) => contact.key || `${contact.user_id}-${contact.context || ''}`;

  const request = (action, data, isMultipart = false) => {
    const body = isMultipart ? data : new URLSearchParams(data);
    return fetch(faepaChatbox.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: isMultipart ? {} : { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body
    }).then(res => res.json());
  };
  const setMobileView = (view) => {
    state.mobileView = view;
    if (!modalEl) { return; }
    const isMobile = mobileMq.matches;
    modalEl.classList.toggle('is-mobile-list', isMobile && view === 'list');
    modalEl.classList.toggle('is-mobile-chat', isMobile && view === 'chat');
    if (backEl) {
      backEl.hidden = !(isMobile && view === 'chat');
    }
  };
  const applyMobileLayout = () => {
    if (!modalEl) { return; }
    const isMobile = mobileMq.matches;
    if (!state.overlayOpen || !isMobile) {
      modalEl.classList.remove('is-mobile-list', 'is-mobile-chat');
      if (backEl) { backEl.hidden = true; }
      return;
    }
    const view = state.mobileView || (state.currentContact ? 'chat' : 'list');
    setMobileView(view);
  };

  mobileMq.addEventListener('change', applyMobileLayout);

  const toggleOverlay = (show) => {
    state.overlayOpen = show;
    overlay.hidden = !show;
    if (show) {
      state.mobileView = mobileMq.matches ? 'list' : 'chat';
      applyMobileLayout();
      loadContacts();
      loadUnread();
      startPolling();
    } else {
      stopPolling();
      state.mobileView = 'list';
      applyMobileLayout();
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
      const contactKey = contactKeyOf(c);
      if (state.currentContact && state.currentContact.key === contactKey) {
        btn.classList.add('is-active');
      }
      btn.dataset.userId = c.user_id;
      btn.dataset.contactKey = contactKey;
      btn.textContent = (isDesk && c.label) ? c.label : (c.name || c.email);
      if (c.unread && c.unread > 0) {
        const badge = document.createElement('span');
        badge.className = 'faepa-chat-contact__badge';
        badge.textContent = c.unread > 99 ? '+99' : c.unread;
        btn.appendChild(badge);
      }
      btn.addEventListener('click', () => {
        if (state.currentContact && state.currentContact.key === contactKey) {
          closeConversation();
          renderContacts();
        } else {
          const nextContact = { ...c, key: contactKey };
          state.currentContact = nextContact;
          loadMessages(nextContact, { resetScroll: true });
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
      is_desk: isDesk ? 1 : 0,
      context: resolvedContext
    }).then(res => {
      if (!res || !res.success) { return; }
      state.contacts = (res.data.contacts || []).map(c => ({
        ...c,
        key: contactKeyOf(c)
      }));
      renderContacts();
    });
  };

  const loadMessages = (contact, options = {}) => {
    if (!contact) { return; }
    if (options.resetScroll) {
      state.autoScroll = true;
    }
    headerEl.textContent = (isDesk && contact.label) ? contact.label : (contact.name || contact.email || 'Contato');
    msgsEl.dataset.empty = defaultEmptyMsg;
    const data = {
      action: 'faepa_chat_messages',
      _ajax_nonce: faepaChatbox.nonce,
      thread_id: contact.thread_id || 0,
      contact_id: contact.user_id,
      is_desk: isDesk ? 1 : 0,
      context: resolvedContext
    };
    if (isDesk && contact.context) {
      data.contact_context = contact.context;
    }
    request('faepa_chat_messages', data).then(res => {
      if (!res || !res.success) { return; }
      state.currentThread = res.data.thread_id;
      state.messages = res.data.messages || [];
      const contactFromServer = res.data.contact || contact;
      const normalizedContact = { ...contactFromServer, key: contactKeyOf(contactFromServer) };
      state.currentContact = normalizedContact;
      headerEl.textContent = (isDesk && normalizedContact.label) ? normalizedContact.label : (normalizedContact.name || normalizedContact.email || 'Contato');
      setMobileView('chat');
      // Atualiza thread_id e limpa badge
      state.contacts = state.contacts.map(c => {
        const cKey = contactKeyOf(c);
        if (cKey === normalizedContact.key) {
          return {
            ...c,
            thread_id: res.data.thread_id,
            unread: 0,
            context: normalizedContact.context
          };
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
    setMobileView('list');
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

    if (file && file.size > MAX_FILE_BYTES) {
      alert('O arquivo deve ter no máximo 5MB.');
      fileEl.value = '';
      return;
    }
    if (!text && !file) { return; }

    const form = new FormData();
    form.append('action', 'faepa_chat_send');
    form.append('_ajax_nonce', faepaChatbox.nonce);
    form.append('thread_id', state.currentThread || 0);
    form.append('contact_id', state.currentContact.user_id);
    form.append('message', text);
    form.append('context', resolvedContext);
    if (isDesk) {
      form.append('is_desk', 1);
      if (state.currentContact.context) {
        form.append('contact_context', state.currentContact.context);
      }
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
      state.autoScroll = true;
      renderMessages();
      loadContacts();
    });
  };

  const loadUnread = () => {
    request('faepa_chat_unread_count', {
      action: 'faepa_chat_unread_count',
      _ajax_nonce: faepaChatbox.nonce,
      is_desk: isDesk ? 1 : 0,
      context: resolvedContext
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
  if (backEl) {
    backEl.addEventListener('click', () => {
      setMobileView('list');
    });
  }
  searchEl.addEventListener('input', debounceSearch);
  formEl.addEventListener('submit', sendMessage);
  msgsEl.addEventListener('scroll', handleScroll);

  // Inicial
  if (overlay) {
    overlay.hidden = true; // garante fechado ao carregar
  }
  applyMobileLayout();
  loadUnread();
})();
