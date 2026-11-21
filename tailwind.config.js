const defaultTheme = require('tailwindcss/defaultTheme');

module.exports = {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],

    // Kelas yang dirangkai dinamis di Blade/JS tidak terdeteksi scanner — wajib di-safelist.
    safelist: [
        'heat-0', 'heat-1', 'heat-2', 'heat-3',
        'dot-HIJAU', 'dot-KUNING', 'dot-MERAH', 'dot-ABU', 'dot-DISCONNECTED', 'dot-UNKNOWN',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['"Plus Jakarta Sans Variable"', 'Figtree', ...defaultTheme.fontFamily.sans],
                display: ['"Fraunces Variable"', 'Georgia', ...defaultTheme.fontFamily.serif],
            },
            colors: {
                cream: '#FDF7F1',
                sand: { 100: '#F8F0E6', 200: '#F0E4D4', 300: '#E4D3BD' },
                synlora: {
                    50:  '#F9F5FF',
                    100: '#F0E6FF',
                    200: '#E0CCFF',
                    300: '#C4A1FF',
                    400: '#A875F5',
                    500: '#8B5CF6',
                    600: '#7C3AED',
                    700: '#6D28D9',
                    800: '#4C1D95',
                    900: '#2D1B4E',
                },
            },
            transitionTimingFunction: {
                synlora: 'cubic-bezier(.16, 1, .3, 1)',
            },
            keyframes: {
                floaty: {
                    '0%,100%': { transform: 'translate3d(0,0,0) scale(1)' },
                    '50%': { transform: 'translate3d(14px,-22px,0) scale(1.05)' },
                },
                rise: {
                    from: { opacity: '0', transform: 'translateY(18px)' },
                    to: { opacity: '1', transform: 'translateY(0)' },
                },
                ring: { from: { 'stroke-dashoffset': '408' }, to: { 'stroke-dashoffset': '120' } },
            },
            animation: {
                floaty: 'floaty 11s ease-in-out infinite',
                'floaty-slow': 'floaty 16s ease-in-out infinite reverse',
                rise: 'rise .8s cubic-bezier(.2,.7,.2,1) both',
                ring: 'ring 2.2s cubic-bezier(.3,.7,.2,1) .4s both',
            },
        },
    },

    plugins: [require('@tailwindcss/forms')],
};
