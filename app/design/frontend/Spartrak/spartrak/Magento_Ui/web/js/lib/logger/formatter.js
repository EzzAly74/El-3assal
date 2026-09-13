/**
 * Spartrak — Magento_Ui's log formatter, without moment.js.
 *
 * ===========================================================================
 * WHY THIS OVERRIDE EXISTS
 * ===========================================================================
 * Core's Magento_Ui/js/lib/logger/formatter declares 'moment' at DEFINE time
 * and uses it on exactly one line:
 *
 *     date = moment(entry.timestamp).format(this.dateFormat_);
 *
 * That is a 57.5KB decoded library (measured on the live homepage via
 * PerformanceResourceTiming.decodedBodySize; ~19KB on the wire) pulled into
 * EVERY page to stamp a timestamp onto console output that production never
 * shows.
 *
 * It is not optional-looking from the outside, either. The chain is:
 *
 *     Magento_Ui/js/lib/logger/console-logger   (loaded on every page)
 *       -> ./formatter
 *         -> moment
 *
 * and console-logger constructs it as `new Formatter()` with no arguments
 * (vendor/magento/module-ui/view/base/web/js/lib/logger/console-logger.js:27),
 * so dateFormat_ is ALWAYS the default 'YYYY-MM-DD HH:mm:ssZ'.
 *
 * ===========================================================================
 * HOW THIS WAS FOUND, BECAUSE THE OBVIOUS ANSWER WAS WRONG
 * ===========================================================================
 * The note in ../knockout/bindings/bootstrap.js says moment is "a hard
 * dependency of Magento_Ui/js/lib/validation/rules, which every validated form
 * needs". That is true but incomplete, and following it sends you the wrong way.
 *
 * After Magento's authentication-popup block was removed from the theme
 * (Magento_Customer/layout/default.xml), validation/rules, mage/validation,
 * jquery.validate and jquery.metadata all stopped loading on the homepage —
 * verified by resource timing, all absent. moment did NOT. The knockout
 * `datepicker` binding was the next suspect and is also innocent: core's
 * datepicker.js declares only ko/underscore/jquery/mage-translate and requires
 * its calendar lazily.
 *
 * The define-time requirer on a page with no forms at all is this file.
 *
 * ===========================================================================
 * WHAT IS PRESERVED
 * ===========================================================================
 * The public shape is unchanged: same module id, same constructor signature
 * (dateFormat, template), same `process(entry)` returning the same
 * '[date] [LEVEL] message' string through mage/utils/template.
 *
 * The token subset below is the complete set appearing in core's default
 * format, plus nothing. `Z` reproduces moment's +HH:mm / -HH:mm local offset.
 * An unrecognised token is left as-is rather than silently dropped, and an
 * unparseable timestamp falls back to its own string form — a log line must
 * never be the thing that throws.
 *
 * If a future caller passes a moment format string using tokens outside this
 * set, it will pass through literally. That is a deliberate, visible failure
 * mode (you see `dddd` in the log) rather than a wrong date, and no caller in
 * this codebase passes one — console-logger is the only consumer.
 */
define(["mage/utils/template"], function (mageTemplate) {
  "use strict";

  /**
   * Left-pads with zeros. Written out rather than using String#padStart so
   * the logger cannot become the reason an older browser needs a polyfill.
   *
   * @param {Number} value
   * @param {Number} [length]
   * @returns {String}
   */
  function pad(value, length) {
    var out = String(value),
      width = length || 2;

    while (out.length < width) {
      out = "0" + out;
    }

    return out;
  }

  /**
   * Local UTC offset in moment's `Z` form (+HH:mm).
   *
   * getTimezoneOffset() is inverted relative to how the offset is written —
   * it returns minutes to ADD to local time to reach UTC — hence the negation.
   *
   * @param {Date} date
   * @returns {String}
   */
  function utcOffset(date) {
    var minutes = -date.getTimezoneOffset(),
      sign = minutes < 0 ? "-" : "+",
      absolute = Math.abs(minutes);

    return sign + pad(Math.floor(absolute / 60)) + ":" + pad(absolute % 60);
  }

  var TOKENS = {
    YYYY: function (date) {
      return pad(date.getFullYear(), 4);
    },
    MM: function (date) {
      return pad(date.getMonth() + 1);
    },
    DD: function (date) {
      return pad(date.getDate());
    },
    HH: function (date) {
      return pad(date.getHours());
    },
    mm: function (date) {
      return pad(date.getMinutes());
    },
    ss: function (date) {
      return pad(date.getSeconds());
    },
    Z: utcOffset,
  };

  /**
   * @param {Number|String|Date} timestamp
   * @param {String} format
   * @returns {String}
   */
  function formatDate(timestamp, format) {
    var date = new Date(timestamp);

    if (isNaN(date.getTime())) {
      return String(timestamp);
    }

    return format.replace(/YYYY|MM|DD|HH|mm|ss|Z/g, function (token) {
      return TOKENS[token](date);
    });
  }

  /**
   * @param {String} dateFormat
   * @param {String} template
   */
  function LogFormatter(dateFormat, template) {
    /**
     * @protected
     * @type {String}
     */
    this.dateFormat_ = "YYYY-MM-DD HH:mm:ssZ";

    /**
     * @protected
     * @type {String}
     */
    this.template_ = "[${ $.date }] [${ $.entry.levelName }] ${ $.message }";

    if (dateFormat) {
      this.dateFormat_ = dateFormat;
    }

    if (template) {
      this.template_ = template;
    }
  }

  /**
   * @param {LogEntry} entry
   * @returns {String}
   */
  LogFormatter.prototype.process = function (entry) {
    var message = mageTemplate.template(entry.message, entry.data),
      date = formatDate(entry.timestamp, this.dateFormat_);

    return mageTemplate.template(this.template_, {
      date: date,
      entry: entry,
      message: message,
    });
  };

  return LogFormatter;
});
