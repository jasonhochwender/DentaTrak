/**
 * DentaTrak Internationalization (i18n) runtime
 *
 * Expects window.__i18n to be set by the server before this file loads.
 * Provides t(), I18n formatting helpers, and a translation source shared
 * with the PHP backend.
 */
(function (global) {
  'use strict';

  var i18n = global.__i18n || {};
  var activeLocale = i18n.resolvedLocale || 'en-US';
  var fallbackLocale = i18n.fallbackLocale || 'en-US';
  var devMode = i18n.devMode || false;
  var allTranslations = i18n.translations || {};

  /**
   * Retrieve a nested translation by dot-notation key.
   */
  function getTranslation(locale, key) {
    var data = allTranslations[locale];
    if (!data || typeof data !== 'object') {
      return null;
    }

    var parts = key.split('.');
    for (var i = 0; i < parts.length; i++) {
      if (typeof data !== 'object' || data === null || !(parts[i] in data)) {
        return null;
      }
      data = data[parts[i]];
    }

    return typeof data === 'string' ? data : null;
  }

  /**
   * Replace {name} placeholders with values from params.
   */
  function interpolate(value, params) {
    if (!params || typeof params !== 'object') {
      return value;
    }

    return value.replace(/\{(\w+)\}/g, function (match, name) {
      return Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : match;
    });
  }

  /**
   * Translate a key into the active locale, falling back to the configured
   * fallback locale, and finally returning an empty string in production
   * while logging a warning in development.
   */
  global.t = function (key, params) {
    var locales = [activeLocale, fallbackLocale];
    var fallbackValue = null;

    for (var i = 0; i < locales.length; i++) {
      var value = getTranslation(locales[i], key);
      if (value !== null) {
        if (fallbackValue === null) {
          fallbackValue = value;
        }
        if (locales[i] === activeLocale) {
          return interpolate(value, params);
        }
      }
    }

    if (fallbackValue !== null) {
      return interpolate(fallbackValue, params);
    }

    if (devMode && global.console && typeof global.console.warn === 'function') {
      global.console.warn('[i18n] Missing translation key: ' + key);
    }

    return '';
  };

  /**
   * Formatting helpers that use the active locale when possible.
   * These are lightweight implementations for the initial i18n architecture.
   */
  global.I18n = {
    locale: activeLocale,
    resolvedLocale: activeLocale,
    fallbackLocale: fallbackLocale,

    t: global.t,

    getResolvedLocale: function () {
      return activeLocale;
    },

    formatDate: function (date, options) {
      options = options || {};
      var d = date instanceof Date ? date : new Date(date);
      if (isNaN(d.getTime())) {
        return String(date);
      }

      if (global.Intl && global.Intl.DateTimeFormat) {
        return new global.Intl.DateTimeFormat(activeLocale, {
          dateStyle: options.style || 'short',
          timeStyle: options.timeStyle || undefined
        }).format(d);
      }

      return (d.getMonth() + 1) + '/' + d.getDate() + '/' + d.getFullYear();
    },

    formatNumber: function (number, options) {
      options = options || {};
      var n = Number(number);
      if (isNaN(n)) {
        return String(number);
      }

      if (global.Intl && global.Intl.NumberFormat) {
        return new global.Intl.NumberFormat(activeLocale, {
          minimumFractionDigits: options.minimumFractionDigits,
          maximumFractionDigits: options.maximumFractionDigits
        }).format(n);
      }

      return String(n);
    },

    formatCurrency: function (amount, currency) {
      currency = currency || 'USD';
      var n = Number(amount);
      if (isNaN(n)) {
        return String(amount);
      }

      if (global.Intl && global.Intl.NumberFormat) {
        return new global.Intl.NumberFormat(activeLocale, {
          style: 'currency',
          currency: currency
        }).format(n);
      }

      return '$' + n.toFixed(2);
    },

    formatRelative: function (date) {
      var timestamp = date instanceof Date ? date.getTime() : new Date(date).getTime();
      if (isNaN(timestamp)) {
        return String(date);
      }

      var now = Date.now();
      var diff = now - timestamp;
      var abs = Math.abs(diff);
      var future = diff < 0;

      if (abs < 60000) {
        return future ? t('common.relative.in_a_moment') : t('common.relative.a_moment_ago');
      }
      if (abs < 3600000) {
        var minutes = Math.round(abs / 60000);
        return future ? I18n.pluralize(minutes, 'common.relative.in_minutes') : I18n.pluralize(minutes, 'common.relative.minutes_ago');
      }
      if (abs < 86400000) {
        var hours = Math.round(abs / 3600000);
        return future ? I18n.pluralize(hours, 'common.relative.in_hours') : I18n.pluralize(hours, 'common.relative.hours_ago');
      }
      if (abs < 604800000) {
        var days = Math.round(abs / 86400000);
        return future ? I18n.pluralize(days, 'common.relative.in_days') : I18n.pluralize(days, 'common.relative.days_ago');
      }

      return I18n.formatDate(date, { style: 'short' });
    },

    pluralize: function (count, key, params) {
      params = params || {};
      var suffix = count === 1 ? '_one' : '_other';
      params.count = count;
      return t(key + suffix, params);
    }
  };

  /**
   * Return a translated display label for a stored/legacy case type value.
   * Uses window.__caseTypeMap (stored -> slug) and falls back to the raw value.
   */
  global.getCaseTypeDisplayLabel = function (stored) {
    var map = global.__caseTypeMap || {};
    var slug = map[stored];
    if (!slug) {
      return stored;
    }
    var label = t('case_types.' + slug);
    return label || stored;
  };
})(window);
