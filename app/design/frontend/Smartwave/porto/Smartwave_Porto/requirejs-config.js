var config = {
    paths: {
        'imagesloaded': 'Smartwave_Porto/js/imagesloaded', 
        'packery': 'Smartwave_Porto/js/packery.pkgd',
        'themeSticky': 'js/jquery.sticky.min',
        'pt_appear': 'Smartwave_Porto/js/apear',
        'pt_animate': 'Smartwave_Porto/js/animate',
        'lazyload': 'js/jquery.lazyload',
        'owlcarousel': 'owl.carousel/owl.carousel',
        'parallax': 'js/jquery.parallax.min',
        'floatelement': 'js/jquery.floatelement',
        'marquee': 'Smartwave_Porto/js/marquee.min',
        'countdown': 'Smartwave_Porto/js/countdown.min',
        'countdownLoader': 'Smartwave_Porto/js/countdown-loader.min',
        'even_move': 'Smartwave_Porto/js/jquery.event.move.min',
        'image_comparison': 'Smartwave_Porto/js/image-comparison',
        'gsap': 'Smartwave_Porto/js/gsap.min',
        'imagefloating': 'Smartwave_Porto/js/imagefloating',
        'fancybox': 'fancybox/js/jquery.fancybox'
    },
    shim: {
        'imagesloaded': {
            deps: ['jquery']
        },
        'packery': {
            deps: ['jquery']
        },
        'themeSticky': {
            deps: ['jquery']
        },
        'pt_animate': {
          deps: ['jquery','pt_appear']
        },
        'owlcarousel': {
            deps: ['jquery']
        },
        'lazyload': {
            deps: ['jquery']
        },
        'floatelement': {
          deps: ['jquery']
        },
        'marquee': {
          deps: ['jquery']
        },
        'countdownLoader': {
          deps: ['jquery','countdown']
        },
        'image_comparison': {
          deps: ['jquery']
        },
        'imagefloating': {
          deps: ['jquery','gsap']
        }
    }
};
