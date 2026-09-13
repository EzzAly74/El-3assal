/**
 * Spartrak — homepage carousel controls.
 *
 * ===========================================================================
 * THIS WIDGET DOES NOT SCROLL ANYTHING. THE BROWSER DOES.
 * ===========================================================================
 * Every rail on the homepage is a plain `overflow-x: auto` container with
 * `scroll-snap-type`. That means it is already swipeable, already
 * keyboard-scrollable, already momentum-scrolled, already correct in RTL, and
 * already accessible — WITHOUT this file. Nothing here is required for the
 * section to work; if the JS never loads, a shopper loses the arrow buttons
 * and keeps the carousel.
 *
 * All this widget adds is:
 *   - arrows that call the native scrollBy()
 *   - dots that call the native scrollTo()
 *   - a progress bar and disabled states, updated from the native scroll event
 *
 * That is CLAUDE.md's "native browser/CSS behaviour over unnecessary
 * JavaScript" taken literally, and it is why one small widget serves the hero
 * banner, both product rails and the showcase rather than four carousel
 * implementations.
 *
 * ===========================================================================
 * WHY THE SCROLL HANDLER CANNOT JANK
 * ===========================================================================
 * Scroll fires far more often than the compositor paints. The handler here
 * does nothing except set a flag and request one animation frame; all reads
 * and writes happen inside that frame, and a second scroll event while a
 * frame is already pending is dropped. So the per-frame cost is bounded no
 * matter how fast the shopper flicks, and the only thing written is a CSS
 * custom property that drives a transform — never a width, never a class that
 * would invalidate layout.
 *
 * ===========================================================================
 * RTL
 * ===========================================================================
 * `scrollLeft` is signed in RTL and browsers historically disagreed about how.
 * Rather than branch per browser, direction is read once from the computed
 * style and every distance is taken through Math.abs() with an explicit sign
 * on the way back out. "Next" therefore means "further along the reading
 * direction" in both locales.
 */
define([
  "jquery",
  "jquery-ui-modules/widget",
  /*
   * The shared click-and-drag gesture, also used by js/spartrak-mega-nav.js.
   * A plain module rather than a widget, so it is required by path: the
   * theme's requirejs-config.js `paths` map exists to give WIDGETS the short
   * names data-mage-init needs, and this is never named in markup.
   */
  "js/spartrak-drag-scroll",
], function ($, widget, dragScroll) {
  "use strict";

  /**
   * The controls whose press means "I have chosen a slide, stop rotating".
   * Anything else the shopper touches inside the hero - the slide itself, a
   * gap, a vertical page scroll that happens to start on it - does not.
   */
  var AUTOPLAY_STOP_CONTROLS =
    "[data-carousel-prev],[data-carousel-next],[data-carousel-dot]";

  $.widget("mage.spartrakHomeCarousel", {
    options: {
      // 'banner' pages a full slide at a time; 'rail' pages by roughly a
      // viewport of cards, which is what feels right on a rail whose
      // items are much narrower than the container.
      mode: "rail",

      // Milliseconds between automatic advances. 0 disables it, which is
      // the default: only the hero opts in. See _setupAutoplay.
      autoplay: 0,
    },

    _create: function () {
      this.track = this.element.find("[data-carousel-track]").first();

      if (!this.track.length) {
        return;
      }

      this.trackEl = this.track[0];
      this.slides = this.track.find("[data-carousel-slide]");

      if (this.slides.length < 2) {
        return;
      }

      // The prev/next buttons live in the SECTION HEADER for product
      // rails and inside the frame for the banner, so they are looked up
      // from the widget root rather than from the track.
      this.prev = this.element.find("[data-carousel-prev]");
      this.next = this.element.find("[data-carousel-next]");
      this.dots = this.element.find("[data-carousel-dot]");
      this.progress = this.element.find("[data-carousel-progress]");

      this.isRtl = window.getComputedStyle(this.trackEl).direction === "rtl";
      this.frameQueued = false;

      this._on(this.prev, { click: this._onPrev });
      this._on(this.next, { click: this._onNext });
      this._on(this.dots, { click: this._onDot });
      this._on(this.track, { scroll: this._queueUpdate });

      this._enableDrag();

      // Also on resize: the step size and the maximum scroll both depend
      // on the container width.
      this._on($(window), { resize: this._queueUpdate });

      this._update();
      this._setupAutoplay();
    },

    /**
     * ===================================================================
     * AUTOPLAY - THE HERO ONLY, AND STILL THE BROWSER DOING THE SCROLLING
     * ===================================================================
     * `_advance` calls the same native scrollTo() the dots already call,
     * with `behavior: 'smooth'`. Nothing here animates anything frame by
     * frame: the browser owns the easing, on the compositor, and it is the
     * identical motion a shopper gets from pressing an arrow. That is what
     * makes it smooth, and it is why this costs one timer and no rAF loop
     * (CLAUDE.md section 13).
     *
     * FOUR THINGS STOP THE CLOCK, each avoiding a real cost:
     *
     *   prefers-reduced-motion  never starts at all. An auto-advancing
     *                           carousel is the canonical thing that
     *                           setting exists to stop (CLAUDE.md 15).
     *   pointer / focus inside  pauses. A shopper reading a slide, or
     *                           tabbing through its link, does not get it
     *                           pulled out from under them.
     *   tab hidden              pauses, via `visibilitychange`. A timer
     *                           firing scrollTo() in a background tab is
     *                           pure battery.
     *   hero scrolled off       pauses, via IntersectionObserver. The hero
     *                           sits at the top of a long page, so for most
     *                           of a session it is not on screen at all.
     *
     * A DELIBERATE INTERACTION STOPS IT FOR GOOD - an arrow, a dot, a
     * mouse drag or a touch swipe. Once a shopper has chosen a slide,
     * moving them off it is the carousel arguing with them.
     *
     * WHAT IS NOT A DELIBERATE INTERACTION, and the bug that taught it:
     * touching the hero. The hero is the first thing on the homepage, so
     * the first flick a shopper makes to scroll DOWN starts on it. Treated
     * as intent, that flick permanently stopped the rotation before it had
     * advanced once, which is why autoplay was reported as not working on
     * mobile at all. Intent is now read from WHAT was pressed (a control)
     * or from WHETHER THE TRACK MOVED (a swipe or a drag), never from the
     * mere fact of a pointer landing on the section.
     *
     * WCAG 2.2.2, honestly. The criterion asks for a mechanism to pause
     * content that moves for more than five seconds. The mechanism here is
     * the visible pagination dots: pressing one halts the rotation
     * permanently, as does hovering or tabbing in. A dedicated pause button
     * would be the textbook answer, and Figma's hero (595:14562) does not
     * draw one - so this is the strongest conformance available without
     * inventing a control the design has no place for, and it is recorded
     * here as a known gap rather than treated as solved.
     */
    _setupAutoplay: function () {
      var interval = parseInt(this.options.autoplay, 10) || 0,
        self = this,
        reduced =
          window.matchMedia &&
          window.matchMedia("(prefers-reduced-motion: reduce)").matches;

      if (interval <= 0 || reduced) {
        return;
      }

      this.autoplayInterval = interval;
      this.autoplayTimer = null;
      this.autoplayStopped = false;
      // Two independent reasons to be paused - a pointer resting on the
      // hero and the tab being in the background - so they are COUNTED
      // rather than toggled. A single boolean would let whichever
      // resumed second restart the clock while the other still held it.
      this.autoplayHolds = 0;

      // ---------------------------------------------------------------
      // A POINTER RESTING ON THE HERO PAUSES IT - HOVER-CAPABLE ONLY
      // ---------------------------------------------------------------
      // A touch screen fires a COMPATIBILITY `mouseenter` on tap and
      // then does not fire the matching `mouseleave` until the shopper
      // taps something else. Bound unconditionally, this pair therefore
      // took a hold on a phone that it never gave back: one tap anywhere
      // on the hero and the rotation was paused for the rest of the
      // page's life. `(hover: hover)` is the question actually being
      // asked here - "can this pointer come to rest on something?" - and
      // it is false on a touch screen and in device emulation, which is
      // where this was reported.
      if (!window.matchMedia || window.matchMedia("(hover: hover)").matches) {
        this._on(this.element, {
          mouseenter: this._holdAutoplay,
          mouseleave: this._releaseAutoplay,
        });
      }

      // Focus has nothing to do with the pointer, so it stays
      // unconditional: a shopper tabbing into a slide's link keeps the
      // slide, on a phone as much as on a desktop.
      this._on(this.element, {
        focusin: this._holdAutoplay,
        focusout: this._releaseAutoplay,
      });

      // ---------------------------------------------------------------
      // A DELIBERATE INTERACTION STOPS IT FOR GOOD - AND ONLY THAT
      // ---------------------------------------------------------------
      // This listener used to fire on ANY pointerdown inside the hero,
      // which is harmless with a mouse and fatal on a phone: the hero is
      // the first thing on the homepage, so the first flick a shopper
      // makes to scroll DOWN the page starts on it - and that vertical
      // page scroll permanently killed the rotation, usually before it
      // had advanced even once. Autoplay was, in practice, dead on
      // mobile. It is now scoped to a press on an actual control.
      //
      // The other two deliberate interactions the class note promises
      // are handled where they can be told apart from a page scroll: a
      // mouse drag in _enableDrag's release handler, a touch swipe in
      // _watchTouch below.
      //
      // Capture phase, so a press on an arrow or a dot registers as
      // intent even though those handlers live on the same element.
      this.element[0].addEventListener(
        "pointerdown",
        function (event) {
          var target = event.target;

          if (
            target &&
            target.closest &&
            target.closest(AUTOPLAY_STOP_CONTROLS)
          ) {
            self._stopAutoplay();
          }
        },
        true,
      );

      this._watchTouch();

      this._onVisibility = function () {
        if (document.hidden) {
          self._holdAutoplay();
        } else {
          self._releaseAutoplay();
        }
      };
      document.addEventListener("visibilitychange", this._onVisibility);

      if (window.IntersectionObserver) {
        // Starts HELD, because the observer's first callback always
        // fires and is what releases it when the hero is already on
        // screen. Starting unheld would double-count that release.
        this.autoplayHolds = 1;
        this.autoplayObserver = new window.IntersectionObserver(
          function (entries) {
            if (entries[entries.length - 1].isIntersecting) {
              self._releaseAutoplay();
            } else {
              self._holdAutoplay();
            }
          },
          { threshold: 0.25 },
        );
        this.autoplayObserver.observe(this.element[0]);

        return;
      }

      this._resumeAutoplay();
    },

    /**
     * ===================================================================
     * TOUCH: PAUSE WHILE THE FINGER IS DOWN, STOP IF IT ACTUALLY SWIPED
     * ===================================================================
     * The touch equivalent of `mouseenter` (pause) and of a mouse drag
     * (stop for good) - and the reason both need their own handler is that
     * a phone gives no way to tell "swiping the hero sideways" from
     * "scrolling the page past it" at pointerdown time. Only afterwards
     * can they be told apart, and the tell is unambiguous: whether the
     * TRACK's own scroll position moved.
     *
     *   finger down            hold. Nothing auto-advances under a hand
     *                          that is resting on it, whatever it goes on
     *                          to do.
     *   finger up, track moved a real horizontal swipe. The shopper has
     *                          picked a slide, so stop for good - the same
     *                          verdict a mouse drag gets.
     *   finger up, track still it was a vertical page scroll that merely
     *                          began on the hero. Release the hold and
     *                          keep rotating; anything else is what made
     *                          autoplay unusable on mobile.
     *
     * Two scrollLeft reads per gesture, both outside any scroll handler,
     * so this adds no per-frame work (CLAUDE.md section 13). `passive`
     * because nothing here calls preventDefault - the browser keeps
     * owning the scroll, as everywhere else in this widget.
     */
    _watchTouch: function () {
      var self = this,
        el = this.trackEl,
        startScroll = 0,
        // Below this a "swipe" is a tap with slop, or a snap-back.
        THRESHOLD = 8;

      el.addEventListener(
        "touchstart",
        function () {
          startScroll = el.scrollLeft;
          self._holdAutoplay();
        },
        { passive: true },
      );

      ["touchend", "touchcancel"].forEach(function (type) {
        el.addEventListener(
          type,
          function () {
            if (Math.abs(el.scrollLeft - startScroll) > THRESHOLD) {
              self._stopAutoplay();

              return;
            }

            self._releaseAutoplay();
          },
          { passive: true },
        );
      });
    },

    _holdAutoplay: function () {
      if (this.autoplayStopped || !this.autoplayInterval) {
        return;
      }

      this.autoplayHolds += 1;
      this._clearAutoplayTimer();
    },

    _releaseAutoplay: function () {
      if (this.autoplayStopped || !this.autoplayInterval) {
        return;
      }

      this.autoplayHolds = Math.max(0, this.autoplayHolds - 1);

      if (this.autoplayHolds === 0) {
        this._resumeAutoplay();
      }
    },

    _resumeAutoplay: function () {
      var self = this;

      this._clearAutoplayTimer();
      this.autoplayTimer = window.setInterval(function () {
        self._advance();
      }, this.autoplayInterval);
    },

    /**
     * Permanent. There is no path back to autoplay in this widget's life.
     */
    _stopAutoplay: function () {
      this.autoplayStopped = true;
      this._clearAutoplayTimer();
    },

    _clearAutoplayTimer: function () {
      if (this.autoplayTimer) {
        window.clearInterval(this.autoplayTimer);
        this.autoplayTimer = null;
      }
    },

    /**
     * One slide forward, wrapping to the first at the end.
     *
     * The wrap is a scrollTo(0) rather than a clone-based infinite loop:
     * cloning slides would duplicate every hero <picture> in the DOM and in
     * the accessibility tree to buy a seam a shopper sees once per rotation.
     */
    _advance: function () {
      var el = this.trackEl,
        maxScroll = el.scrollWidth - el.clientWidth;

      if (Math.abs(el.scrollLeft) >= maxScroll - 1) {
        el.scrollTo({ left: 0, behavior: "smooth" });

        return;
      }

      // Through _goTo, like the arrows and the dots: one slide forward
      // from wherever the hero actually is, landing on a snap point.
      this._goTo(this._currentIndex() + 1);
    },

    /**
     * jQuery UI calls this on teardown. `_on` bindings and the observer's
     * own subscription go with the widget; the document-level listener and
     * the interval do not, so they are released by hand.
     */
    _destroy: function () {
      this._clearAutoplayTimer();

      if (this.autoplayObserver) {
        this.autoplayObserver.disconnect();
        this.autoplayObserver = null;
      }

      if (this._onVisibility) {
        document.removeEventListener("visibilitychange", this._onVisibility);
        this._onVisibility = null;
      }
    },

    /**
     * Click-and-drag with a mouse.
     *
     * THE GESTURE ITSELF MOVED to js/spartrak-drag-scroll.js (2026-09-10),
     * when the header's mega-nav category row needed the same thing. What
     * used to be ~60 lines of pointer bookkeeping here — the threshold, the
     * scroll-snap suspend/restore, the one-shot click suppression, the
     * dragstart block and the pointerleave/pointercancel cleanup — is now
     * one implementation shared by both callers. See that module for why
     * each of those pieces is needed; none of the behaviour changed.
     *
     * The one carousel-specific part stays here: a completed drag is a
     * deliberate slide change, so it stops autoplay for good. It is decided
     * on RELEASE rather than on press, because at press time a mouse-down on
     * the hero is indistinguishable from a click on a slide's link and only
     * the distance travelled says which it was — which is exactly the
     * question `onDragEnd` answers (it fires only past the threshold).
     */
    _enableDrag: function () {
      var self = this;

      dragScroll(this.trackEl, {
        onDragEnd: function () {
          self._stopAutoplay();
        },
      });
    },

    /**
     * ===================================================================
     * EVERY MOVE IS TO A SLIDE INDEX. NOTHING SCROLLS BY A PIXEL DISTANCE.
     * ===================================================================
     * This replaces a `_step()` that returned a number of PIXELS and a
     * `_scrollBy()` that applied it, and it fixes a reported defect that
     * neither of them could avoid: pressing "next" on the category rail
     * advanced TWO cards, so a shopper aiming for the tile beside the one
     * they were looking at landed on the one after it.
     *
     * That had two independent causes, and both are gone here because the
     * question changed from "how far?" to "which slide?".
     *
     *   1. THE GAP WAS INVISIBLE TO THE OLD STEP. It measured a card with
     *      jQuery's outerWidth(true), which counts margins and NOT flex
     *      `gap` - and every rail on this page is a flex row with a gap
     *      (12px on the tiles, 20 on the rails and the showcase). So the
     *      step was short by one gap per card and never lined up with a
     *      snap point.
     *
     *   2. THE OLD STEP WAS A FRACTION OF A CARD. `visible - slideWidth`
     *      is only a whole number of cards if the container happens to be
     *      an exact multiple of one. On the 716px tiles rail it came to
     *      392px against a 336px pitch - 1.17 cards. A scroll that lands
     *      between two snap points is then resolved by the BROWSER, and
     *      which side it rounds to depends on the UA's proximity
     *      threshold, the exact fractional remainder and the momentum of
     *      the smooth scroll. That is the "animation issue": the rail was
     *      not choosing a card, it was landing between two and letting
     *      snapping guess.
     *
     * `_pitch()` reads the real centre-to-centre distance off the DOM, so
     * the gap is included without this file knowing any gap value.
     * `_goTo()` then moves to an exact multiple of it, which IS a snap
     * point by construction - so the smooth scroll and the snap agree
     * instead of fighting, and one press moves exactly the number of cards
     * `_perPress()` names.
     *
     * Still the native scroller doing the scrolling, still one scrollTo
     * per press (the class note holds).
     */

    /**
     * Distance from the start of one slide to the start of the next -
     * width PLUS gap.
     *
     * Measured from two offsetLefts rather than computed from a width and
     * a gap: offsetLeft is a layout fact, so this is correct for any rail
     * on the page without a per-rail constant, and it stays correct if a
     * gap changes in the stylesheet. Falls back to the first slide's own
     * width for a single-slide track, where there is no second offset to
     * subtract and no paging to do anyway.
     *
     * @return {Number} always > 0, so it is safe to divide by
     */
    _pitch: function () {
      var first = this.slides.get(0),
        second = this.slides.get(1),
        pitch = 0;

      if (first && second) {
        pitch = Math.abs(second.offsetLeft - first.offsetLeft);
      }

      if (!pitch) {
        pitch = this.slides.first().outerWidth(true);
      }

      return pitch || this.trackEl.clientWidth || 1;
    },

    /**
     * How many slides one press of an arrow moves. A WHOLE number, never
     * zero.
     *
     * A banner pages one slide at a time - the slide is the whole frame.
     *
     * A rail pages by however many cards are FULLY visible, less one, so
     * the card a shopper was reading stays on screen as an anchor rather
     * than the rail jumping to an unrelated set. On the category tiles
     * two cards fit, so that is one card per press - which is what was
     * asked for, and it now comes out of the same rule that gives the
     * wide product rails their three, instead of being a special case.
     *
     * @return {Number}
     */
    _perPress: function () {
      if (this.options.mode === "banner") {
        return 1;
      }

      var perView = Math.floor(this.trackEl.clientWidth / this._pitch());

      return Math.max(1, perView - 1);
    },

    /**
     * Which slide is at the reading edge right now.
     *
     * Math.abs() for the same reason every other read in this file uses
     * it: scrollLeft is negative in RTL. Rounded, so a rail resting a few
     * sub-pixels off a snap point still reports the slide it is showing.
     *
     * @return {Number}
     */
    _currentIndex: function () {
      return Math.round(Math.abs(this.trackEl.scrollLeft) / this._pitch());
    },

    /**
     * Scrolls so that slide `index` sits at the reading edge.
     *
     * The target is `index * pitch` and NOT the slide's own offsetLeft.
     * Both would be right in LTR; only this one is unambiguous in RTL,
     * where offsetLeft is measured on a physical axis whose origin is the
     * far end of the reading direction. Deriving the position from the
     * pitch keeps it in the same signed space `_currentIndex()` and
     * `_update()` already read, so the three cannot disagree - and it is
     * exactly the arithmetic that guarantees a snap point.
     *
     * Clamped to the track's real maximum, so a press near the end scrolls
     * to the end rather than asking for a position past it.
     *
     * @param {Number} index
     */
    _goTo: function (index) {
      var el = this.trackEl,
        maxScroll = Math.max(0, el.scrollWidth - el.clientWidth),
        distance = Math.min(Math.max(index, 0) * this._pitch(), maxScroll);

      el.scrollTo({
        left: this.isRtl ? -distance : distance,
        behavior: "smooth",
      });
    },

    _onPrev: function (event) {
      event.preventDefault();
      this._goTo(this._currentIndex() - this._perPress());
    },

    _onNext: function (event) {
      event.preventDefault();
      this._goTo(this._currentIndex() + this._perPress());
    },

    _onDot: function (event) {
      event.preventDefault();

      // Through the same _goTo as the arrows, so a dot and an arrow
      // cannot land a rail in two different places.
      this._goTo(parseInt($(event.currentTarget).attr("data-index"), 10) || 0);
    },

    /**
     * Coalesces any number of scroll events into at most one frame of
     * work. See the class note.
     */
    _queueUpdate: function () {
      if (this.frameQueued) {
        return;
      }

      this.frameQueued = true;

      window.requestAnimationFrame(
        function () {
          this.frameQueued = false;
          this._update();
        }.bind(this),
      );
    },

    _update: function () {
      var el = this.trackEl;
      var maxScroll = el.scrollWidth - el.clientWidth;
      var offset = Math.abs(el.scrollLeft);
      var ratio = maxScroll > 0 ? Math.min(offset / maxScroll, 1) : 0;

      this._setDisabled(this.prev, offset <= 1);
      this._setDisabled(this.next, offset >= maxScroll - 1);

      if (this.progress.length) {
        // The bar is 25% wide, so it can travel 300% of its own width.
        // One custom property; the transform is composited.
        this.progress[0].style.setProperty(
          "--spartrak-rail-progress",
          (this.isRtl ? -300 * ratio : 300 * ratio) + "%",
        );
      }

      if (this.dots.length) {
        // The PITCH, not the container width. They are the same number
        // on the hero, whose slides are the full frame, but only one of
        // them is the right question - and the day a dotted carousel
        // has slides narrower than its frame, the container width would
        // silently light the wrong dot.
        this._updateDots(offset, this._pitch());
      }
    },

    _updateDots: function (offset, pitch) {
      var index = Math.round(offset / (pitch || 1));

      this.dots.each(function (i, dot) {
        var selected = i === index;

        dot.setAttribute("aria-selected", selected ? "true" : "false");
      });

      // Keeps assistive tech in step with what is visually on screen.
      this.slides.each(function (i, slide) {
        if (i === index) {
          slide.removeAttribute("aria-hidden");
        } else {
          slide.setAttribute("aria-hidden", "true");
        }
      });
    },

    _setDisabled: function (buttons, disabled) {
      if (!buttons.length) {
        return;
      }

      buttons.each(function (i, button) {
        button.disabled = disabled;
      });
    },
  });

  return $.mage.spartrakHomeCarousel;
});
