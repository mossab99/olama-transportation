(function () {
    'use strict';

    var config = window.olamaTransportationI18n || {};
    var translations = config.translations || {};

    window.olamaTransportationTranslate = function (text) {
        var key = text == null ? '' : String(text).trim();
        return config.language === 'ar' && Object.prototype.hasOwnProperty.call(translations, key)
            ? translations[key]
            : key;
    };

    function translateTextNode(node) {
        var value = node.nodeValue || '';
        var key = value.trim();
        if (!key || !Object.prototype.hasOwnProperty.call(translations, key)) return;
        node.nodeValue = value.replace(key, translations[key]);
    }

    function translateElement(element) {
        if (!element || element.nodeType !== 1) return;
        ['placeholder', 'title', 'aria-label'].forEach(function (attribute) {
            var value = element.getAttribute(attribute);
            if (value && Object.prototype.hasOwnProperty.call(translations, value.trim())) {
                element.setAttribute(attribute, translations[value.trim()]);
            }
        });
        Array.prototype.forEach.call(element.childNodes, function (node) {
            if (node.nodeType === 3) translateTextNode(node);
        });
        Array.prototype.forEach.call(element.children, translateElement);
    }

    function initialize() {
        var root = document.querySelector('.olama-transportation-wrap');
        if (!root) return;
        root.setAttribute('dir', config.direction || 'ltr');
        document.documentElement.setAttribute('data-olama-transportation-language', config.language || 'en');
        if (config.language !== 'ar') return;
        var nativeAlert = window.alert;
        var nativeConfirm = window.confirm;
        window.alert = function (message) { return nativeAlert.call(window, window.olamaTransportationTranslate(message)); };
        window.confirm = function (message) { return nativeConfirm.call(window, window.olamaTransportationTranslate(message)); };
        translateElement(root);
        new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                Array.prototype.forEach.call(mutation.addedNodes, function (node) {
                    if (node.nodeType === 3) translateTextNode(node);
                    else translateElement(node);
                });
            });
        }).observe(root, {childList: true, subtree: true});
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
    else initialize();
}());
