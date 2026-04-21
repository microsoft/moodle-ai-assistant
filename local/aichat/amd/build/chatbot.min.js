/**
 * AI Chat - Main chatbot AMD module.
 *
 * Manages the floating chatbot widget: FAB toggle, chat panel, SSE streaming,
 * message history, feedback, file uploads, action cards, and follow-up chips.
 *
 * @module     local_aichat/chatbot
 * @package    local_aichat
 * @copyright  2026 Moodle AI Chat Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/templates', 'local_aichat/sanitizer'], function(Ajax, Templates, Sanitizer) {
    'use strict';

    // -------------------------------------------------------------------------
    // Module state.
    // -------------------------------------------------------------------------
    var state = {
        courseid: 0,
        sectionid: 0,
        cmid: 0,
        firstName: '',
        courseName: '',
        strings: {},
        courseSettings: {},
        themeSettings: {},
        lang: 'en',
        showPrivacyNotice: false,
        privacyNotice: '',
        dailyLimit: 0,
        maxMsgLength: 2000,
        threadId: null,
        isOpen: false,
        isSending: false,
        eventSource: null,
        recognition: null,
        isRecording: false
    };

    // DOM references (cached after render).
    var dom = {};

    // -------------------------------------------------------------------------
    // Markdown-to-HTML converter (lightweight).
    // -------------------------------------------------------------------------

    /**
     * Convert a basic markdown string to HTML.
     *
     * Supports: bold, italic, inline code, code blocks, headers, lists, links,
     * blockquotes, horizontal rules, tables.
     *
     * @param {string} md - Markdown source text.
     * @return {string} HTML string.
     */
    function markdownToHtml(md) {
        if (!md) {
            return '';
        }

        var html = md;

        // Fenced code blocks (``` ... ```).
        html = html.replace(/```(\w*)\n([\s\S]*?)```/g, function(match, lang, code) {
            var cls = lang ? ' class="language-' + escapeHtml(lang) + '"' : '';
            return '<pre><code' + cls + '>' + escapeHtml(code.replace(/\n$/, '')) + '</code></pre>';
        });

        // Inline code (`...`).
        html = html.replace(/`([^`\n]+)`/g, '<code>$1</code>');

        // Headings (### ... to # ...).
        html = html.replace(/^######\s+(.*)$/gm, '<h6>$1</h6>');
        html = html.replace(/^#####\s+(.*)$/gm, '<h5>$1</h5>');
        html = html.replace(/^####\s+(.*)$/gm, '<h4>$1</h4>');
        html = html.replace(/^###\s+(.*)$/gm, '<h3>$1</h3>');
        html = html.replace(/^##\s+(.*)$/gm, '<h2>$1</h2>');
        html = html.replace(/^#\s+(.*)$/gm, '<h1>$1</h1>');

        // Horizontal rules.
        html = html.replace(/^---+$/gm, '<hr>');

        // Blockquotes (> ...).
        html = html.replace(/^>\s+(.*)$/gm, '<blockquote>$1</blockquote>');

        // Bold (**...**).
        html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');

        // Italic (*...*).
        html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');

        // Links [text](url).
        html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2">$1</a>');

        // Unordered lists (- item or * item).
        html = html.replace(/^[\s]*[-*]\s+(.*)$/gm, '<li>$1</li>');
        html = html.replace(/((?:<li>.*<\/li>\n?)+)/g, '<ul>$1</ul>');

        // Ordered lists (1. item).
        html = html.replace(/^[\s]*\d+\.\s+(.*)$/gm, '<li>$1</li>');

        // Tables (basic pipe tables).
        html = html.replace(/^(\|.+\|)\n\|[\s-:|]+\|\n((?:\|.+\|\n?)+)/gm, function(match, headerLine, bodyLines) {
            var headers = headerLine.split('|').filter(Boolean).map(function(c) {
                return '<th>' + c.trim() + '</th>';
            }).join('');
            var rows = bodyLines.trim().split('\n').map(function(row) {
                var cells = row.split('|').filter(Boolean).map(function(c) {
                    return '<td>' + c.trim() + '</td>';
                }).join('');
                return '<tr>' + cells + '</tr>';
            }).join('');
            return '<table><thead><tr>' + headers + '</tr></thead><tbody>' + rows + '</tbody></table>';
        });

        // Paragraphs: wrap remaining plain text lines.
        html = html.replace(/^(?!<[a-zA-Z\/])(.*[^\s].*)$/gm, '<p>$1</p>');

        // Clean up empty paragraphs.
        html = html.replace(/<p>\s*<\/p>/g, '');

        return html;
    }

    /**
     * Escape HTML entities.
     *
     * @param {string} text - Raw text.
     * @return {string} Escaped text.
     */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    // -------------------------------------------------------------------------
    // Helpers.
    // -------------------------------------------------------------------------

    /**
     * Format a unix timestamp to a short time string.
     *
     * @param {number} timestamp - Unix timestamp in seconds.
     * @return {string} Formatted time (HH:MM).
     */
    function formatTime(timestamp) {
        var d = new Date(timestamp * 1000);
        return d.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
    }

    /**
     * Add a message bubble to the messages container.
     *
     * @param {object} msg - Message object {id, role, message, feedback, timecreated, suggestions}.
     * @return {Promise} Resolves once the message is in the DOM.
     */
    function appendMessage(msg) {
        var isAssistant = msg.role === 'assistant';
        var feedbackUp = msg.feedback === 1;
        var feedbackDown = msg.feedback === -1;
        var suggestions = msg.suggestions || [];

        var templateContext = {
            id: msg.id,
            role: msg.role,
            message: isAssistant ? Sanitizer.sanitize(markdownToHtml(msg.message)) : escapeHtml(msg.message),
            timeformatted: formatTime(msg.timecreated),
            isassistant: isAssistant,
            feedbackup: feedbackUp,
            feedbackdown: feedbackDown,
            hassuggestions: suggestions.length > 0,
            suggestions: suggestions,
            avatarUrl: state.themeSettings.avatarUrl || ''
        };

        return Templates.render('local_aichat/message', templateContext).then(function(html) {
            // Insert before typing indicator.
            var typing = dom.typing;
            if (typing && typing.parentNode === dom.messages) {
                typing.insertAdjacentHTML('beforebegin', html);
            } else {
                dom.messages.insertAdjacentHTML('beforeend', html);
            }
            scrollToBottom();
            bindMessageEvents();
            return;
        }).catch(function() {
            // Fallback: simple HTML injection on template failure.
            var bubbleClass = isAssistant ? 'aichat-bubble-assistant' : 'aichat-bubble-user';
            var content = isAssistant ? Sanitizer.sanitize(markdownToHtml(msg.message)) : escapeHtml(msg.message);
            var html = '<div class="aichat-message aichat-message-' + msg.role + ' aichat-message-in"' +
                       ' data-messageid="' + msg.id + '">' +
                       '<div class="aichat-bubble-col">' +
                       '<div class="aichat-bubble ' + bubbleClass + '">' +
                       '<div class="aichat-bubble-content">' + content + '</div>' +
                       '</div></div></div>';
            dom.messages.insertAdjacentHTML('beforeend', html);
            scrollToBottom();
        });
    }

    /**
     * Scroll messages container to bottom.
     */
    function scrollToBottom() {
        if (dom.messages) {
            dom.messages.scrollTop = dom.messages.scrollHeight;
        }
    }

    /**
     * Show or hide the typing indicator.
     *
     * @param {boolean} visible - Whether to show.
     */
    function setTyping(visible) {
        if (dom.typing) {
            dom.typing.classList.toggle('aichat-hidden', !visible);
            if (visible) {
                scrollToBottom();
            }
        }
    }

    /**
     * Enable or disable the input area.
     *
     * @param {boolean} enabled - Whether input is enabled.
     */
    function setInputEnabled(enabled) {
        if (dom.input) {
            dom.input.disabled = !enabled;
        }
        if (dom.sendBtn) {
            dom.sendBtn.disabled = !enabled || !dom.input.value.trim();
        }
    }

    /**
     * Update daily limit display.
     *
     * @param {number} remaining - Messages remaining.
     */
    function updateDailyLimit(remaining) {
        if (!dom.dailyLimit || !state.dailyLimit) {
            return;
        }

        if (remaining <= 0 && state.dailyLimit > 0) {
            dom.dailyLimit.textContent = state.strings.dailylimitreached.replace('{$a}', '24');
            dom.dailyLimit.classList.add('aichat-limit-reached');
            setInputEnabled(false);
        } else if (state.dailyLimit > 0) {
            dom.dailyLimit.textContent = state.strings.remainingmessages.replace('{$a}', remaining);
            dom.dailyLimit.classList.remove('aichat-limit-reached');
        }
    }

    // -------------------------------------------------------------------------
    // Core actions.
    // -------------------------------------------------------------------------

    /**
     * Load thread history via web service.
     */
    function loadHistory() {
        Ajax.call([{
            methodname: 'local_aichat_get_history',
            args: {courseid: state.courseid}
        }])[0].then(function(result) {
            state.threadId = result.threadid || null;

            // Clear messages area.
            var existingMessages = dom.messages.querySelectorAll('.aichat-message');
            for (var i = 0; i < existingMessages.length; i++) {
                existingMessages[i].remove();
            }

            if (result.messages && result.messages.length > 0) {
                // Hide welcome/action cards.
                if (dom.welcome) {
                    dom.welcome.classList.add('aichat-hidden');
                }

                result.messages.forEach(function(msg) {
                    appendMessage(msg);
                });
            } else {
                // Show welcome/action cards.
                if (dom.welcome) {
                    dom.welcome.classList.remove('aichat-hidden');
                }
            }
            return;
        }).catch(function() {
            // Silently fail — user sees empty chat.
        });
    }

    /**
     * Create a new thread.
     */
    function newThread() {
        if (state.isSending) {
            return;
        }

        Ajax.call([{
            methodname: 'local_aichat_new_thread',
            args: {courseid: state.courseid}
        }])[0].then(function(result) {
            state.threadId = result.threadid;

            // Clear messages and errors, show welcome state with greeting.
            clearErrors();
            var existingMessages = dom.messages.querySelectorAll('.aichat-message');
            for (var i = 0; i < existingMessages.length; i++) {
                existingMessages[i].remove();
            }

            if (dom.welcome) {
                dom.welcome.classList.remove('aichat-hidden');
            }

            // Append greeting as assistant message.
            if (result.greeting) {
                appendMessage({
                    id: result.messageid,
                    role: 'assistant',
                    message: result.greeting,
                    feedback: 0,
                    timecreated: Math.floor(Date.now() / 1000),
                    suggestions: []
                });
            }
            return;
        }).catch(function() {
            // Silently fail.
        });
    }

    /**
     * Send a message via SSE streaming.
     *
     * @param {string} messageText - The user's message text.
     */
    function sendMessage(messageText) {
        if (state.isSending || !messageText.trim()) {
            return;
        }

        // Validate length client-side.
        if (messageText.length > state.maxMsgLength) {
            return;
        }

        state.isSending = true;
        setInputEnabled(false);

        // Clear any previous error messages.
        clearErrors();

        // Append user message immediately, then create assistant bubble after it's in the DOM.
        var userTimestamp = Math.floor(Date.now() / 1000);
        appendMessage({
            id: 0,
            role: 'user',
            message: messageText,
            feedback: 0,
            timecreated: userTimestamp,
            suggestions: []
        }).then(function() {
            startStreaming(messageText);
        });

        // Hide welcome state.
        if (dom.welcome) {
            dom.welcome.classList.add('aichat-hidden');
        }

        // Clear input.
        dom.input.value = '';
        autoResizeTextarea();
    }

    /**
     * Start SSE streaming after user bubble is in the DOM.
     *
     * @param {string} messageText - The user's message text.
     */
    function startStreaming(messageText) {
        // Create streaming assistant bubble (includes embedded typing dots).
        var assistantBubble = createStreamingBubble();

        // Build SSE URL.
        var params = new URLSearchParams();
        params.set('sesskey', M.cfg.sesskey);
        params.set('courseid', state.courseid);
        params.set('message', messageText);
        if (state.sectionid) {
            params.set('sectionid', state.sectionid);
        }
        if (state.cmid) {
            params.set('cmid', state.cmid);
        }

        var sseUrl = M.cfg.wwwroot + '/local/aichat/ajax.php?' + params.toString();

        // Close any existing EventSource.
        if (state.eventSource) {
            state.eventSource.close();
        }

        var fullResponse = '';
        var es = new EventSource(sseUrl);
        state.eventSource = es;

        es.addEventListener('token', function(e) {
            try {
                var data = JSON.parse(e.data);
                fullResponse += data.token;
                updateStreamingBubble(assistantBubble, fullResponse);
                setTyping(false);
            } catch (err) {
                // Ignore parse errors on individual tokens.
            }
        });

        es.addEventListener('done', function(e) {
            es.close();
            state.eventSource = null;
            state.isSending = false;
            setTyping(false);

            try {
                var data = JSON.parse(e.data);

                // Finalize the assistant bubble.
                finalizeStreamingBubble(assistantBubble, fullResponse, data);

                // Update daily limit.
                if (typeof data.remaining !== 'undefined') {
                    updateDailyLimit(data.remaining);
                }
            } catch (err) {
                // Finalize with what we have.
                finalizeStreamingBubble(assistantBubble, fullResponse, {});
            }

            setInputEnabled(true);
            dom.input.focus();
        });

        es.addEventListener('error', function(e) {
            es.close();
            state.eventSource = null;
            state.isSending = false;
            setTyping(false);

            // Try to parse error data (custom error event).
            var errorMsg = state.strings.assistantunavailable;
            if (e.data) {
                try {
                    var data = JSON.parse(e.data);
                    if (data.message) {
                        // Check for burst limit.
                        if (data.message.indexOf('burst') !== -1 || data.message.indexOf('wait') !== -1) {
                            errorMsg = state.strings.burstwait.replace('{$a}', '60');
                        } else {
                            errorMsg = data.message;
                        }
                    }
                } catch (err) {
                    // Use default error.
                }
            }

            // Remove empty streaming bubble and show error.
            if (assistantBubble && assistantBubble.parentNode) {
                assistantBubble.remove();
            }
            showError(errorMsg);
            setInputEnabled(true);
            dom.input.focus();
        });

        // EventSource onerror (connection errors).
        es.onerror = function() {
            if (es.readyState === EventSource.CLOSED) {
                return; // Already handled by event listeners.
            }
            es.close();
            state.eventSource = null;
            state.isSending = false;
            setTyping(false);

            if (assistantBubble && assistantBubble.parentNode) {
                assistantBubble.remove();
            }

            // Fallback to non-streaming.
            sendMessageFallback(messageText);
        };
    }

    /**
     * Fallback: send message via standard AJAX (non-streaming).
     *
     * @param {string} messageText - The message to send.
     */
    function sendMessageFallback(messageText) {
        setTyping(true);
        state.isSending = true;

        Ajax.call([{
            methodname: 'local_aichat_send_message',
            args: {
                courseid: state.courseid,
                message: messageText,
                sectionid: state.sectionid,
                cmid: state.cmid
            }
        }])[0].then(function(result) {
            setTyping(false);
            state.isSending = false;

            if (result.success) {
                appendMessage({
                    id: result.messageid,
                    role: 'assistant',
                    message: result.response,
                    feedback: 0,
                    timecreated: Math.floor(Date.now() / 1000),
                    suggestions: result.suggestions || []
                });

                if (typeof result.remaining !== 'undefined') {
                    updateDailyLimit(result.remaining);
                }
            }

            setInputEnabled(true);
            dom.input.focus();
            return;
        }).catch(function(err) {
            setTyping(false);
            state.isSending = false;
            showError(err.message || state.strings.assistantunavailable);
            setInputEnabled(true);
        });
    }

    /**
     * Create a streaming assistant message bubble.
     *
     * @return {HTMLElement} The bubble container element.
     */
    function createStreamingBubble() {
        var wrapper = document.createElement('div');
        wrapper.className = 'aichat-message aichat-message-assistant aichat-message-in';
        wrapper.setAttribute('data-messageid', '0');

        var avatarHtml = '';
        if (state.themeSettings.avatarUrl) {
            avatarHtml = '<div class="aichat-avatar"><img src="' +
                escapeHtml(state.themeSettings.avatarUrl) +
                '" alt="" class="aichat-avatar-img" /></div>';
        } else {
            avatarHtml = '<div class="aichat-avatar"><div class="aichat-avatar-default">' +
                '<svg viewBox="0 0 48 48" width="16" height="16" fill="none" xmlns="http://www.w3.org/2000/svg">' +
                '<line x1="24" y1="4" x2="24" y2="9" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>' +
                '<circle cx="24" cy="3" r="2" fill="currentColor"/>' +
                '<rect x="6" y="9" width="36" height="26" rx="7" ry="7" fill="currentColor"/>' +
                '<rect x="10" y="14" width="28" height="16" rx="4" ry="4" fill="white" opacity="0.15"/>' +
                '<circle cx="17" cy="22" r="3" fill="white"/>' +
                '<circle cx="18" cy="21" r="1.2" fill="currentColor"/>' +
                '<circle cx="31" cy="22" r="3" fill="white"/>' +
                '<circle cx="32" cy="21" r="1.2" fill="currentColor"/>' +
                '<path d="M18 28 Q24 32.5 30 28" stroke="white" stroke-width="1.8" fill="none" stroke-linecap="round"/>' +
                '<rect x="2" y="17" width="5" height="7" rx="2" ry="2" fill="currentColor" opacity="0.7"/>' +
                '<rect x="41" y="17" width="5" height="7" rx="2" ry="2" fill="currentColor" opacity="0.7"/>' +
                '<rect x="19" y="35" width="10" height="4" rx="2" ry="2" fill="currentColor" opacity="0.8"/>' +
                '<rect x="12" y="39" width="24" height="6" rx="3" ry="3" fill="currentColor"/>' +
                '</svg></div></div>';
        }

        wrapper.innerHTML = avatarHtml +
            '<div class="aichat-bubble-col">' +
            '<div class="aichat-bubble aichat-bubble-assistant">' +
            '<div class="aichat-typing-dots aichat-streaming-dots">' +
            '<span></span><span></span><span></span>' +
            '</div>' +
            '<div class="aichat-bubble-content aichat-streaming aichat-hidden"></div>' +
            '</div>' +
            '</div>';

        // Insert before typing indicator.
        if (dom.typing && dom.typing.parentNode === dom.messages) {
            dom.messages.insertBefore(wrapper, dom.typing);
        } else {
            dom.messages.appendChild(wrapper);
        }
        scrollToBottom();
        return wrapper;
    }

    /**
     * Update streaming bubble content as tokens arrive.
     *
     * @param {HTMLElement} bubble - The streaming bubble element.
     * @param {string} text - The full response text so far.
     */
    /**
     * Strip the [SUGGESTIONS]...[/SUGGESTIONS] block from response text.
     *
     * @param {string} text - Raw response text.
     * @return {string} Text without the suggestions block.
     */
    function stripSuggestions(text) {
        return text.replace(/\[SUGGESTIONS\][\s\S]*?(\[\/SUGGESTIONS\]|$)/g, '').trim();
    }

    function updateStreamingBubble(bubble, text) {
        // Remove typing dots on first token.
        var dots = bubble.querySelector('.aichat-streaming-dots');
        if (dots) {
            dots.remove();
        }
        var content = bubble.querySelector('.aichat-bubble-content');
        if (content) {
            content.classList.remove('aichat-hidden');
            content.innerHTML = Sanitizer.sanitize(markdownToHtml(stripSuggestions(text)));
        }
        scrollToBottom();
    }

    /**
     * Finalize a streaming bubble: remove streaming class, set final content,
     * add feedback buttons and suggestion chips.
     *
     * @param {HTMLElement} bubble - The streaming bubble element.
     * @param {string} fullText - The complete response text.
     * @param {object} doneData - The done event data {messageid, remaining, suggestions}.
     */
    function finalizeStreamingBubble(bubble, fullText, doneData) {
        var content = bubble.querySelector('.aichat-bubble-content');
        if (content) {
            content.classList.remove('aichat-streaming');
            content.innerHTML = Sanitizer.sanitize(markdownToHtml(stripSuggestions(fullText)));
        }

        // Set the real message ID.
        var msgId = doneData.messageid || 0;
        bubble.setAttribute('data-messageid', msgId);

        // Add time.
        var bubbleEl = bubble.querySelector('.aichat-bubble');
        if (bubbleEl) {
            var existingMeta = bubbleEl.querySelector('.aichat-bubble-meta');
            if (!existingMeta) {
                var meta = document.createElement('div');
                meta.className = 'aichat-bubble-meta';
                meta.innerHTML = '<span class="aichat-time">' +
                    formatTime(Math.floor(Date.now() / 1000)) + '</span>';
                bubbleEl.appendChild(meta);
            }
        }

        // Add feedback + suggestions.
        var actions = document.createElement('div');
        actions.className = 'aichat-message-actions';

        // Feedback buttons.
        actions.innerHTML = '<div class="aichat-feedback" data-messageid="' + msgId + '">' +
            '<button class="aichat-feedback-btn aichat-feedback-up" data-feedback="1" ' +
            'aria-label="' + escapeHtml(state.strings.thumbsup || 'Thumbs up') + '" ' +
            'title="' + escapeHtml(state.strings.thumbsup || 'Thumbs up') + '">' +
            '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" ' +
            'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
            '<path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/>' +
            '</svg></button>' +
            '<button class="aichat-feedback-btn aichat-feedback-down" data-feedback="-1" ' +
            'aria-label="' + escapeHtml(state.strings.thumbsdown || 'Thumbs down') + '" ' +
            'title="' + escapeHtml(state.strings.thumbsdown || 'Thumbs down') + '">' +
            '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" ' +
            'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
            '<path d="M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3zm7-13h2.67A2.31 2.31 0 0 1 22 4v7a2.31 2.31 0 0 1-2.33 2H17"/>' +
            '</svg></button></div>';

        // Suggestion chips.
        var suggestions = doneData.suggestions || [];
        if (suggestions.length > 0) {
            var chipsHtml = '<div class="aichat-suggestions">';
            suggestions.forEach(function(s) {
                chipsHtml += '<button class="aichat-suggestion-chip">' + escapeHtml(s) + '</button>';
            });
            chipsHtml += '</div>';
            actions.innerHTML += chipsHtml;
        }

        var bubbleCol = bubble.querySelector('.aichat-bubble-col');
        if (bubbleCol) {
            bubbleCol.appendChild(actions);
        } else {
            bubble.appendChild(actions);
        }
        bindMessageEvents();
        scrollToBottom();
    }

    /**
     * Remove all error messages from the chat.
     */
    function clearErrors() {
        if (dom.messages) {
            var errors = dom.messages.querySelectorAll('.aichat-error');
            for (var i = 0; i < errors.length; i++) {
                errors[i].remove();
            }
        }
    }

    /**
     * Show an error message in the chat.
     *
     * @param {string} message - The error message.
     */
    function showError(message) {
        var errorEl = document.createElement('div');
        errorEl.className = 'aichat-error';
        errorEl.textContent = message;

        if (dom.typing && dom.typing.parentNode === dom.messages) {
            dom.messages.insertBefore(errorEl, dom.typing);
        } else {
            dom.messages.appendChild(errorEl);
        }
        scrollToBottom();
    }

    // -------------------------------------------------------------------------
    // Event binding.
    // -------------------------------------------------------------------------

    /**
     * Bind click handlers for feedback buttons and suggestion chips.
     */
    function bindMessageEvents() {
        // Feedback buttons.
        var feedbackBtns = dom.messages.querySelectorAll('.aichat-feedback-btn:not([data-bound])');
        feedbackBtns.forEach(function(btn) {
            btn.setAttribute('data-bound', '1');
            btn.addEventListener('click', function() {
                var feedbackVal = parseInt(this.getAttribute('data-feedback'), 10);
                var container = this.closest('.aichat-feedback');
                var msgId = parseInt(container.getAttribute('data-messageid'), 10);
                if (!msgId) {
                    return;
                }

                // Toggle active state.
                container.querySelectorAll('.aichat-feedback-btn').forEach(function(b) {
                    b.classList.remove('aichat-feedback-active');
                });
                this.classList.add('aichat-feedback-active');

                // Submit feedback.
                var self = this;
                Ajax.call([{
                    methodname: 'local_aichat_submit_feedback',
                    args: {messageid: msgId, feedback: feedbackVal},
                    fail: function() {
                        // Revert visual state on failure.
                        self.classList.remove('aichat-feedback-active');
                    }
                }]);
            });
        });

        // Suggestion chips.
        var chips = dom.messages.querySelectorAll('.aichat-suggestion-chip:not([data-bound])');
        chips.forEach(function(chip) {
            chip.setAttribute('data-bound', '1');
            chip.addEventListener('click', function() {
                var text = this.textContent.trim();
                if (text) {
                    sendMessage(text);
                }
            });
        });
    }

    /**
     * Auto-resize the textarea based on content.
     */
    function autoResizeTextarea() {
        if (!dom.input) {
            return;
        }
        dom.input.style.height = 'auto';
        dom.input.style.overflow = 'hidden';
        var scrollH = dom.input.scrollHeight;
        var maxH = 120;
        if (scrollH > maxH) {
            dom.input.style.height = maxH + 'px';
            dom.input.style.overflow = 'auto';
        } else {
            dom.input.style.height = scrollH + 'px';
        }
    }

    // -------------------------------------------------------------------------
    // Privacy notice.
    // -------------------------------------------------------------------------

    /**
     * Check and potentially show privacy notice overlay.
     *
     * @return {boolean} True if notice was shown (blocks interaction), false if OK to proceed.
     */
    function checkPrivacyNotice() {
        if (!state.showPrivacyNotice) {
            return false;
        }

        var storageKey = 'aichat_privacy_accepted_' + state.courseid;
        if (localStorage.getItem(storageKey)) {
            return false;
        }

        // Show the overlay.
        var overlay = document.getElementById('aichat-privacy-overlay');
        if (overlay) {
            overlay.classList.remove('aichat-hidden');

            var agreeBtn = document.getElementById('aichat-privacy-agree');
            if (agreeBtn) {
                agreeBtn.addEventListener('click', function() {
                    localStorage.setItem(storageKey, '1');
                    overlay.classList.add('aichat-hidden');
                    loadHistory();
                });
            }
            return true;
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Initialization.
    // -------------------------------------------------------------------------

    return {
        /**
         * Initialize the chatbot widget.
         *
         * @param {number} courseid - Course ID.
         * @param {number} sectionid - Current section ID (0 if none).
         * @param {number} cmid - Current course module ID (0 if none).
         * @param {string} firstName - User's first name.
         * @param {string} courseName - Course full name.
         * @param {object} strings - Language strings.
         * @param {object} courseSettings - Per-course settings {enable_export, enable_upload}.
         * @param {object} themeSettings - Theme settings {primaryColor, secondaryColor, headerTitle, avatarUrl}.
         * @param {string} lang - User language code.
         * @param {boolean} showPrivacyNotice - Whether to show privacy notice.
         * @param {string} privacyNotice - Privacy notice HTML content.
         * @param {number} dailyLimit - Daily message limit (0 = unlimited).
         * @param {number} maxMsgLength - Maximum message length.
         */
        init: function(courseid, sectionid, cmid, firstName, courseName, strings,
                       courseSettings, themeSettings, lang, showPrivacyNotice,
                       privacyNotice, dailyLimit, maxMsgLength) {

            // Populate state.
            state.courseid = courseid;
            state.sectionid = sectionid;
            state.cmid = cmid;
            state.firstName = firstName;
            state.courseName = courseName;
            state.strings = strings;
            state.courseSettings = courseSettings;
            state.themeSettings = themeSettings;
            state.lang = lang;
            state.showPrivacyNotice = showPrivacyNotice;
            state.privacyNotice = privacyNotice;
            state.dailyLimit = dailyLimit;
            state.maxMsgLength = maxMsgLength || 2000;

            // Render the chatbot panel via template.
            var templateContext = {
                avatarUrl: themeSettings.avatarUrl || '',
                headerTitle: themeSettings.headerTitle || strings.courseassistant,
                enableExport: courseSettings.enable_export,
                enableUpload: courseSettings.enable_upload,
                showPrivacyNotice: showPrivacyNotice,
                privacyNotice: privacyNotice,
                maxMsgLength: state.maxMsgLength
            };

            Templates.render('local_aichat/chatbot', templateContext).then(function(html) {
                // Inject into page body.
                var container = document.createElement('div');
                container.id = 'aichat-container';
                container.innerHTML = html;
                document.body.appendChild(container);

                // Cache DOM references.
                dom.fab = document.getElementById('aichat-fab');
                dom.panel = document.getElementById('aichat-panel');
                dom.messages = document.getElementById('aichat-messages');
                dom.typing = document.getElementById('aichat-typing');
                dom.welcome = document.getElementById('aichat-welcome');
                dom.input = document.getElementById('aichat-input');
                dom.sendBtn = document.getElementById('aichat-send-btn');
                dom.dailyLimit = document.getElementById('aichat-daily-limit');
                dom.micBtn = document.getElementById('aichat-mic-btn');

                // Init speech recognition.
                initSpeechRecognition();

                // Bind core events.
                bindCoreEvents();

                return;
            }).catch(function(err) {
                // eslint-disable-next-line no-console
                console.error('AI Chat: Failed to render template', err);
            });
        }
    };

    /**
     * Bind core UI events: FAB toggle, close, new chat, send, export, keyboard.
     */
    function bindCoreEvents() {
        // FAB button — toggle panel.
        dom.fab.addEventListener('click', function() {
            state.isOpen = !state.isOpen;
            togglePanel(state.isOpen);
        });

        // Close button.
        var closeBtn = document.getElementById('aichat-close-btn');
        if (closeBtn) {
            closeBtn.addEventListener('click', function() {
                state.isOpen = false;
                togglePanel(false);
            });
        }

        // New chat button.
        var newChatBtn = document.getElementById('aichat-newchat-btn');
        if (newChatBtn) {
            newChatBtn.addEventListener('click', function() {
                newThread();
            });
        }

        // Send button.
        dom.sendBtn.addEventListener('click', function() {
            var text = dom.input.value.trim();
            if (text) {
                sendMessage(text);
            }
        });

        // Input events.
        dom.input.addEventListener('input', function() {
            autoResizeTextarea();
            dom.sendBtn.disabled = !this.value.trim() || state.isSending;
        });

        // Keyboard: Enter to send, Shift+Enter for newline, Escape to close.
        dom.input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                var text = dom.input.value.trim();
                if (text && !state.isSending) {
                    sendMessage(text);
                }
            }
            if (e.key === 'Escape') {
                state.isOpen = false;
                togglePanel(false);
            }
        });

        // Export button.
        var exportBtn = document.getElementById('aichat-export-btn');
        if (exportBtn) {
            exportBtn.addEventListener('click', function() {
                var url = M.cfg.wwwroot + '/local/aichat/export.php?courseid=' +
                    state.courseid + '&format=txt&sesskey=' + M.cfg.sesskey;
                window.open(url, '_blank');
            });
        }

        // Action cards.
        var actionCards = document.querySelectorAll('.aichat-action-card');
        actionCards.forEach(function(card) {
            card.addEventListener('click', function() {
                var action = this.getAttribute('data-action');
                var actionStrings = {
                    'tellmeaboutcourse': state.strings.tellmeaboutcourse,
                    'summarizesection': state.strings.summarizesection,
                    'createquiz': state.strings.createquiz
                };
                var text = actionStrings[action] || '';
                if (text) {
                    sendMessage(text);
                }
            });
        });

        // Upload button (if enabled).
        var uploadBtn = document.getElementById('aichat-upload-btn');
        if (uploadBtn) {
            uploadBtn.addEventListener('click', function() {
                // Open Moodle file picker — simplified for now.
                var fileInput = document.createElement('input');
                fileInput.type = 'file';
                fileInput.accept = 'image/*,.pdf,.txt,.doc,.docx';
                fileInput.addEventListener('change', function() {
                    if (this.files && this.files[0]) {
                        showUploadPreview(this.files[0]);
                    }
                });
                fileInput.click();
            });
        }

        // Mobile: handle virtual keyboard resize via visualViewport API.
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', function() {
                if (state.isOpen && dom.panel) {
                    var viewportHeight = window.visualViewport.height;
                    var isMobile = window.innerWidth <= 768;
                    if (isMobile) {
                        dom.panel.style.height = viewportHeight + 'px';
                        dom.panel.style.maxHeight = viewportHeight + 'px';
                    }
                }
            });
            window.visualViewport.addEventListener('scroll', function() {
                if (state.isOpen && dom.panel && window.innerWidth <= 768) {
                    dom.panel.style.top = window.visualViewport.offsetTop + 'px';
                }
            });
        }

        // Mobile: close panel on hardware/browser back button.
        window.addEventListener('popstate', function() {
            if (state.isOpen) {
                state.isOpen = false;
                togglePanel(false);
            }
        });

        // Mic button.
        if (dom.micBtn) {
            dom.micBtn.addEventListener('click', function() {
                toggleSpeechRecognition();
            });
        }
    }

    /**
     * Initialize the Web Speech API for voice input.
     */
    function initSpeechRecognition() {
        var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRecognition) {
            // Browser does not support Speech Recognition — keep mic button hidden.
            return;
        }

        state.recognition = new SpeechRecognition();
        state.recognition.continuous = false;
        state.recognition.interimResults = true;
        state.recognition.lang = state.lang || 'en';

        state.recognition.onresult = function(event) {
            var transcript = '';
            for (var i = event.resultIndex; i < event.results.length; i++) {
                transcript += event.results[i][0].transcript;
            }
            if (dom.input) {
                dom.input.value = transcript;
                autoResizeTextarea();
                dom.sendBtn.disabled = !transcript.trim() || state.isSending;
            }
        };

        state.recognition.onend = function() {
            state.isRecording = false;
            if (dom.micBtn) {
                dom.micBtn.classList.remove('aichat-mic-active');
                dom.micBtn.setAttribute('aria-label', state.strings.voiceinput || 'Voice input');
                dom.micBtn.setAttribute('title', state.strings.voiceinput || 'Voice input');
            }
        };

        state.recognition.onerror = function() {
            state.isRecording = false;
            if (dom.micBtn) {
                dom.micBtn.classList.remove('aichat-mic-active');
            }
        };

        // Show mic button — browser supports it.
        if (dom.micBtn) {
            dom.micBtn.classList.remove('aichat-hidden');
        }
    }

    /**
     * Toggle speech recognition on/off.
     */
    function toggleSpeechRecognition() {
        if (!state.recognition) {
            return;
        }

        if (state.isRecording) {
            state.recognition.stop();
            state.isRecording = false;
            if (dom.micBtn) {
                dom.micBtn.classList.remove('aichat-mic-active');
                dom.micBtn.setAttribute('aria-label', state.strings.voiceinput || 'Voice input');
                dom.micBtn.setAttribute('title', state.strings.voiceinput || 'Voice input');
            }
        } else {
            state.recognition.start();
            state.isRecording = true;
            if (dom.micBtn) {
                dom.micBtn.classList.add('aichat-mic-active');
                dom.micBtn.setAttribute('aria-label', state.strings.voicelistening || 'Listening...');
                dom.micBtn.setAttribute('title', state.strings.voicelistening || 'Listening...');
            }
        }
    }

    /**
     * Toggle the chat panel visibility.
     *
     * @param {boolean} open - Whether to open (true) or close (false).
     */
    function togglePanel(open) {
        if (!dom.panel || !dom.fab) {
            return;
        }

        if (open) {
            dom.panel.classList.remove('aichat-hidden');
            dom.panel.classList.add('aichat-open');
            dom.fab.classList.add('aichat-fab-active');

            // Push history state so mobile back-button closes the panel.
            if (window.innerWidth <= 768) {
                window.history.pushState({aichatOpen: true}, '');
            }

            // Prevent body scroll on mobile when panel is open.
            if (window.innerWidth <= 768) {
                document.body.style.overflow = 'hidden';
            }

            // Load history if first open.
            if (!state.threadId) {
                var noticeShown = checkPrivacyNotice();
                if (!noticeShown) {
                    loadHistory();
                }
            }

            dom.input.focus();
        } else {
            dom.panel.classList.add('aichat-hidden');
            dom.panel.classList.remove('aichat-open');
            dom.fab.classList.remove('aichat-fab-active');

            // Reset mobile-specific inline styles.
            dom.panel.style.height = '';
            dom.panel.style.maxHeight = '';
            dom.panel.style.top = '';
            document.body.style.overflow = '';

            // Close any in-flight SSE stream.
            if (state.eventSource) {
                state.eventSource.close();
                state.eventSource = null;
                state.isSending = false;
                setTyping(false);
                setInputEnabled(true);
            }
        }
    }

    /**
     * Show upload file preview.
     *
     * @param {File} file - The selected file.
     */
    function showUploadPreview(file) {
        var preview = document.getElementById('aichat-upload-preview');
        if (!preview) {
            return;
        }

        preview.classList.remove('aichat-hidden');

        if (file.type.indexOf('image/') === 0) {
            var reader = new FileReader();
            reader.onload = function(e) {
                preview.innerHTML = '<div class="aichat-upload-thumb">' +
                    '<img src="' + e.target.result + '" alt="' + escapeHtml(file.name) + '" />' +
                    '<button class="aichat-upload-remove" aria-label="Remove">&times;</button>' +
                    '</div>';
                bindRemoveUpload(preview);
            };
            reader.readAsDataURL(file);
        } else {
            preview.innerHTML = '<div class="aichat-upload-thumb">' +
                '<span class="aichat-upload-filename">' + escapeHtml(file.name) + '</span>' +
                '<button class="aichat-upload-remove" aria-label="Remove">&times;</button>' +
                '</div>';
            bindRemoveUpload(preview);
        }
    }

    /**
     * Bind click event to remove upload preview.
     *
     * @param {HTMLElement} preview - The preview container.
     */
    function bindRemoveUpload(preview) {
        var removeBtn = preview.querySelector('.aichat-upload-remove');
        if (removeBtn) {
            removeBtn.addEventListener('click', function() {
                preview.innerHTML = '';
                preview.classList.add('aichat-hidden');
            });
        }
    }
});
