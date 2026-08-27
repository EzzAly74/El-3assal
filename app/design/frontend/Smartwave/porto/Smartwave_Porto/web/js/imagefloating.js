
define([
    'jquery'
], function ($) {
  "use strict";
  theme = theme || {};

	var instanceName = '__textelfloating';

	var PluginTElFloaing = function( $el, opts ) { 
		return this.initialize( $el, opts );
	};

	PluginTElFloaing.defaults = {
		offset: 0,
	};

	PluginTElFloaing.prototype = {
		initialize: function( $el, opts ) {
			if ( $el.data( instanceName ) ) {
				return this;
			}
			this.$el = $el;

			this
				.setData( opts )
				.event();
			return this;
		},
		setData: function( opts ) {
			this.options = $.extend( true, {}, PluginTElFloaing.defaults, opts );
			this.$el.data( instanceName, this );
			return this;
		},
		event: function() {
			this.mouseEnterFunc = this.mouseEnter.bind( this );
			this.$el.on( 'mouseenter', this.mouseEnterFunc );
			this.mouseOutFunc = this.mouseOut.bind( this );
			this.$el.on( 'mouseleave', this.mouseOutFunc );
		},
		mouseEnter: function( e ) {
			$( '.thumb-info-floating-element-clone' ).remove();
			var $thumbFloatingEl = $( '.thumb-info-floating-element', this.$el );
			if ( $thumbFloatingEl.length ) {
				this.$elClone = $thumbFloatingEl.clone().addClass( 'thumb-info-floating-element-clone' ).removeClass( 'd-none' ).appendTo( document.body );
			} else if ( this.$el.hasClass( 'tb-hover-content' ) && this.$el.children().length > 0 ) {
				if ( this.$el.hasClass( 'with-link' ) ) {
					$thumbFloatingEl = this.$el.children( ':nth-child(2)' );
				} else {
					$thumbFloatingEl = this.$el.children( ':first' );
				}
				this.$elClone = $thumbFloatingEl.clone().addClass( 'thumb-tb-floating-el' ).appendTo( document.body ).wrap( '<div class="thumb-info-floating-element-clone page-wrapper"></div>' );
			} else {
				return;
			}


			$( '.thumb-info-floating-element-clone' ).css( {
				left: e.clientX + parseInt( this.options.offset ),
				top: e.clientY + parseInt( this.options.offset )
			} ).fadeIn( 300 );

			gsap.to( '.thumb-info-floating-element-clone', 1, {
				css: {
					scaleX: 1,
					scaleY: 1
				}
			} );

			this.mouseMoveFunc = this.mouseMove.bind( this );
			$( document.body ).on( 'mousemove', this.mouseMoveFunc );
		},
		mouseMove: function( e ) {
			if ( this.$elClone.length && this.$elClone.closest( 'html' ).length ) {
				gsap.to( '.thumb-info-floating-element-clone', 0.5, {
					css: {
						left: e.clientX + parseInt( this.options.offset ),
						top: e.clientY + parseInt( this.options.offset )
					}
				} );
			}
		},
		mouseOut: function( e ) {
			if ( this.$elClone.length && this.$elClone.closest( 'html' ).length ) {
				gsap.to( '.thumb-info-floating-element-clone', 0.5, {
					css: {
						scaleX: 0.5,
						scaleY: 0.5,
						opacity: 0
					}
				} );
			}
		},
		clearData: function( e ) {
			this.$elClone.remove();
			this.$el.off( 'mouseenter', this.mouseEnterFunc );
			this.$el.off( 'mouseout', this.mouseOutFunc );
			$( document.body ).off( 'mousemove', this.mouseMoveFunc );
		}
	}

	$.extend( theme, {
		PluginTElFloaing: PluginTElFloaing
	} );
	$.fn.themePluginTIFloating = function() {
		if ( typeof gsap !== 'undefined' ) {
			return this.map( function() {
				var $this = $( this );
				if ( $this.data( instanceName ) ) {
					return $this.data( instanceName );
				} else {
					return new PluginTElFloaing( $this, $this.data( 'plugin-tfloating' ) );
				}
			} );
		} else {
			return false;
		}
	}
});
