import Splide from '@splidejs/splide';

const options = {
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
};

function initShowcaseCarousels() {
    document.querySelectorAll('.showcase-carousel').forEach((carousel) => {
        if (carousel.classList.contains('is-initialized')) {
            return;
        }

        new Splide(carousel, options).mount();
    });
}

document.addEventListener('DOMContentLoaded', initShowcaseCarousels);
document.addEventListener('livewire:navigated', initShowcaseCarousels);
