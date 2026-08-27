var config = {
    paths: {
        'imagesloaded': 'Smartwave_Filterproducts/js/imagesloaded',
        'packery': 'Smartwave_Filterproducts/js/packery.pkgd',
        'lazyload': 'Smartwave_Filterproducts/js/lazyload/jquery.lazyload',
        'owlcarousel': 'Smartwave_Filterproducts/js/owl.carousel/owl.carousel.min',
    },
    shim: {
        'packery': {
            deps: ['jquery','imagesloaded']
        },
        'lazyload': {
            deps: ['jquery']
        },
        'owlcarousel': {
            deps: ['jquery']
        }
    }
};
