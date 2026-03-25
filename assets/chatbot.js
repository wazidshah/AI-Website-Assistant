/**
 * AI Website Assistant – Frontend Chat Widget
 *
 * Initialised via aiAssistantConfig global (set by wp_localize_script).
 *
 * @package AI_Website_Assistant
 */
(function () {
	'use strict';

	/* ── Config ──────────────────────────────────────────────────────────── */
	var cfg = window.aiAssistantConfig || {};
	var REST_URL  = cfg.restUrl  || '/wp-json/ai-assistant/v1/chat';
	var NONCE     = cfg.nonce    || '';
	var BOT_NAME  = cfg.botName  || 'AI Assistant';
	var GREETING  = cfg.greeting || 'Hi! How can I help you today?';

	/* ── Build HTML ─────────────────────────────────────────────────────── */
	var widgetHTML = [
		'<button id="ai-assistant-toggle" aria-label="Open AI chat" aria-expanded="false">',
		'  <span class="ai-icon-chat" aria-hidden="true">💬</span>',
		'  <span class="ai-icon-close" aria-hidden="true">✕</span>',
		'</button>',

		'<div id="ai-assistant-widget" role="dialog" aria-label="AI Assistant Chat" aria-modal="true">',

		'  <div id="ai-assistant-header">',
		'    <div class="ai-avatar">🤖</div>',
		'    <div class="ai-header-info">',
		'      <div class="ai-header-name" id="ai-bot-name"></div>',
		'      <div class="ai-header-status"><span class="ai-status-dot"></span>Online</div>',
		'    </div>',
		'    <button id="ai-close-btn" aria-label="Close chat">✕</button>',
		'  </div>',

		'  <div id="ai-assistant-messages" role="log" aria-live="polite" aria-label="Chat messages">',
		'    <div id="ai-typing-indicator" class="ai-msg ai-msg-bot" aria-label="AI is typing">',
		'      <div class="ai-bubble">',
		'        <div class="ai-dot"></div>',
		'        <div class="ai-dot"></div>',
		'        <div class="ai-dot"></div>',
		'      </div>',
		'    </div>',
		'  </div>',

		'  <div id="ai-assistant-footer">',
		'    <textarea id="ai-assistant-input" rows="1"',
		'      placeholder="Type your message…"',
		'      aria-label="Message input"',
		'      maxlength="500"',
		'    ></textarea>',
		'    <button id="ai-send-btn" aria-label="Send message" disabled>',
		'      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">',
		'        <line x1="22" y1="2" x2="11" y2="13"/>',
		'        <polygon points="22 2 15 22 11 13 2 9 22 2"/>',
		'      </svg>',
		'    </button>',
		'  </div>',

		'  <div class="ai-powered">Powered by Google Gemini</div>',
		'</div>',
	].join('\n');

	/* ── Inject into DOM ────────────────────────────────────────────────── */
	var container = document.createElement('div');
	container.id  = 'ai-assistant-root';
	container.innerHTML = widgetHTML;
	document.body.appendChild(container);

	/* ── Element refs ────────────────────────────────────────────────────── */
	var toggleBtn  = document.getElementById('ai-assistant-toggle');
	var widget     = document.getElementById('ai-assistant-widget');
	var closeBtn   = document.getElementById('ai-close-btn');
	var messages   = document.getElementById('ai-assistant-messages');
	var input      = document.getElementById('ai-assistant-input');
	var sendBtn    = document.getElementById('ai-send-btn');
	var typing     = document.getElementById('ai-typing-indicator');
	var botNameEl  = document.getElementById('ai-bot-name');

	botNameEl.textContent = BOT_NAME;

	/* ── State ───────────────────────────────────────────────────────────── */
	var isOpen    = false;
	var isBusy    = false;
	var sessionId = 'ai_session_' + Date.now();

	/* ── Helpers ─────────────────────────────────────────────────────────── */
	function escapeHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	/**
	 * Simple markdown-like formatter: bold, line breaks.
	 */
	function formatBotText(text) {
		return escapeHtml(text)
			.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
			.replace(/\*(.*?)\*/g, '<em>$1</em>')
			.replace(/\n/g, '<br>');
	}

	function appendMessage(role, text, sources) {
		var isBot = role === 'bot';

		var msgEl  = document.createElement('div');
		msgEl.className = 'ai-msg ' + (isBot ? 'ai-msg-bot' : 'ai-msg-user');

		var bubbleEl = document.createElement('div');
		bubbleEl.className = 'ai-bubble';

		if (isBot) {
			bubbleEl.innerHTML = formatBotText(text);
		} else {
			bubbleEl.textContent = text;
		}

		msgEl.appendChild(bubbleEl);

		// Append source links for bot messages.
		if (isBot && sources && sources.length) {
			var srcEl = document.createElement('div');
			srcEl.className = 'ai-sources';
			var links = sources.slice(0, 3).map(function(s) {
				return '<a href="' + escapeHtml(s.url) + '" target="_blank" rel="noopener">📄 ' + escapeHtml(s.title) + '</a>';
			});
			srcEl.innerHTML = 'Sources: ' + links.join(' · ');
			msgEl.appendChild(srcEl);
		}

		// Insert before typing indicator.
		messages.insertBefore(msgEl, typing);
		scrollToBottom();

		return msgEl;
	}

	function scrollToBottom() {
		messages.scrollTop = messages.scrollHeight;
	}

	function showTyping() {
		typing.classList.add('visible');
		scrollToBottom();
	}

	function hideTyping() {
		typing.classList.remove('visible');
	}

	function setInputState(busy) {
		isBusy          = busy;
		input.disabled  = busy;
		sendBtn.disabled = busy || input.value.trim().length === 0;
	}

	/* ── Open / Close ────────────────────────────────────────────────────── */
	function openWidget() {
		isOpen = true;
		widget.classList.add('is-open');
		toggleBtn.classList.add('is-open');
		toggleBtn.setAttribute('aria-expanded', 'true');
		input.focus();
	}

	function closeWidget() {
		isOpen = false;
		widget.classList.remove('is-open');
		toggleBtn.classList.remove('is-open');
		toggleBtn.setAttribute('aria-expanded', 'false');
		toggleBtn.focus();
	}

	toggleBtn.addEventListener('click', function () {
		if (isOpen) { closeWidget(); } else { openWidget(); }
	});

	closeBtn.addEventListener('click', closeWidget);

	// Close on Escape key.
	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape' && isOpen) { closeWidget(); }
	});

	/* ── Send Flow ───────────────────────────────────────────────────────── */
	function sendMessage() {
		var question = input.value.trim();
		if (!question || isBusy) { return; }

		// Show user bubble.
		appendMessage('user', question);

		input.value = '';
		input.style.height = 'auto';
		setInputState(true);
		showTyping();

		fetch(REST_URL, {
			method:  'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   NONCE,
			},
			body: JSON.stringify({ question: question }),
		})
		.then(function(response) {
			return response.json().then(function(data) {
				return { status: response.status, data: data };
			});
		})
		.then(function(result) {
			hideTyping();
			setInputState(false);

			if (result.status === 200 && result.data.answer) {
				appendMessage('bot', result.data.answer, result.data.sources || []);
			} else {
				var errMsg = (result.data && result.data.message)
					? result.data.message
					: 'Sorry, I encountered an error. Please try again.';
				appendMessage('bot', errMsg);
			}
		})
		.catch(function(err) {
			hideTyping();
			setInputState(false);
			appendMessage('bot', 'Network error. Please check your connection and try again.');
			console.error('[AI Assistant]', err);
		});
	}

	sendBtn.addEventListener('click', sendMessage);

	input.addEventListener('input', function() {
		// Auto-grow textarea.
		this.style.height = 'auto';
		this.style.height = Math.min(this.scrollHeight, 100) + 'px';

		sendBtn.disabled = isBusy || this.value.trim().length === 0;
	});

	input.addEventListener('keydown', function(e) {
		if (e.key === 'Enter' && !e.shiftKey) {
			e.preventDefault();
			sendMessage();
		}
	});

	/* ── Greeting ─────────────────────────────────────────────────────────── */
	appendMessage('bot', GREETING);

})();
