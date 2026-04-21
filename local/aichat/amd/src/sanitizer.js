/**
 * AI Chat - Client-side HTML sanitizer (AMD module).
 *
 * Provides a lightweight DOM-based sanitizer that mirrors the server-side
 * output_sanitizer.php whitelist. Used as defense-in-depth before injecting
 * any AI-generated HTML into the DOM.
 *
 * @module     local_aichat/sanitizer
 * @package    local_aichat
 * @copyright  2026 Moodle AI Chat Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    /** Allowed HTML tags — mirrors output_sanitizer.php ALLOWED_TAGS. */
    var ALLOWED_TAGS = new Set([
        'strong', 'em', 'b', 'i', 'u', 'code', 'pre', 'ul', 'ol', 'li', 'p', 'br',
        'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'span', 'div', 'hr', 'dl', 'dt', 'dd'
    ]);

    /** Allowed attributes per tag — mirrors output_sanitizer.php ALLOWED_ATTRIBUTES. */
    var ALLOWED_ATTRS = {
        'a': new Set(['href', 'title', 'rel', 'target']),
        'td': new Set(['colspan', 'rowspan']),
        'th': new Set(['colspan', 'rowspan', 'scope']),
        'ol': new Set(['start', 'type']),
        'code': new Set(['class'])
    };

    /** Dangerous URI schemes. */
    var DANGEROUS_URI_RE = /^\s*(javascript|data|vbscript)\s*:/i;

    /**
     * Walk a DOM tree and sanitize nodes in-place.
     *
     * @param {Node} node - The root node to sanitize.
     */
    function walkAndSanitize(node) {
        var childNodes = Array.prototype.slice.call(node.childNodes);
        for (var i = 0; i < childNodes.length; i++) {
            var child = childNodes[i];

            if (child.nodeType === Node.TEXT_NODE) {
                // Text nodes are safe.
                continue;
            }

            if (child.nodeType !== Node.ELEMENT_NODE) {
                // Remove comments, processing instructions, etc.
                node.removeChild(child);
                continue;
            }

            var tagName = child.tagName.toLowerCase();

            if (!ALLOWED_TAGS.has(tagName)) {
                // Replace disallowed element with its children (unwrap).
                while (child.firstChild) {
                    node.insertBefore(child.firstChild, child);
                }
                node.removeChild(child);
                continue;
            }

            // Remove disallowed attributes.
            var allowedAttrs = ALLOWED_ATTRS[tagName] || new Set();
            var attrs = Array.prototype.slice.call(child.attributes);
            for (var j = 0; j < attrs.length; j++) {
                var attrName = attrs[j].name.toLowerCase();

                // Always remove event handlers.
                if (attrName.indexOf('on') === 0) {
                    child.removeAttribute(attrs[j].name);
                    continue;
                }

                if (!allowedAttrs.has(attrName)) {
                    child.removeAttribute(attrs[j].name);
                    continue;
                }

                // Check for dangerous URIs in href/src.
                if (attrName === 'href' || attrName === 'src') {
                    if (DANGEROUS_URI_RE.test(attrs[j].value)) {
                        child.removeAttribute(attrs[j].name);
                    }
                }
            }

            // Enforce safe link attributes on anchors.
            if (tagName === 'a') {
                child.setAttribute('rel', 'noopener noreferrer');
                child.setAttribute('target', '_blank');
            }

            // Recurse into children.
            walkAndSanitize(child);
        }
    }

    return {
        /**
         * Sanitize an HTML string using a DOM-based whitelist approach.
         *
         * @param {string} html - The untrusted HTML string to sanitize.
         * @return {string} The sanitized HTML string.
         */
        sanitize: function(html) {
            if (!html) {
                return '';
            }

            var doc = new DOMParser().parseFromString(
                '<div>' + html + '</div>',
                'text/html'
            );
            var container = doc.body.firstChild;

            walkAndSanitize(container);

            return container.innerHTML;
        }
    };
});
