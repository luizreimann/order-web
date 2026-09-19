document.addEventListener('DOMContentLoaded', function() {
    const carousels = document.querySelectorAll('.showcase-carousel');
    
    carousels.forEach(function(carousel) {
        new Splide(carousel, {
            type: 'slide',
            perPage: 3,
            perMove: 1,
            gap: '1rem',
            padding: { right: '5rem' },
            arrows: true,
            pagination: false,
            breakpoints: {
                768: {
                    perPage: 1,
                    padding: { right: '3rem' },
                },
                1024: {
                    perPage: 2,
                    padding: { right: '4rem' },
                },
            },
        }).mount();
    });
});

document.addEventListener('livewire:navigated', function() {
    const carousels = document.querySelectorAll('.showcase-carousel');
    
    carousels.forEach(function(carousel) {
        if (!carousel.classList.contains('is-initialized')) {
            new Splide(carousel, {
                type: 'slide',
                perPage: 3,
                perMove: 1,
                gap: '1rem',
                padding: { right: '5rem' },
                arrows: true,
                pagination: false,
                breakpoints: {
                    768: {
                        perPage: 1,
                        padding: { right: '3rem' },
                    },
                    1024: {
                        perPage: 2,
                        padding: { right: '4rem' },
                    },
                },
            }).mount();
        }
    });
});
