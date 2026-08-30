define(['jquery'], function ($) {
    'use strict';

    return function () {
        $('#paymob-toggle-btn').on('click', function () {
            $('#paymob-config-wrapper').slideToggle();
        });
    };
});