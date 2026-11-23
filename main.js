// main.js
document.addEventListener('DOMContentLoaded', () => {

    // === LIKE BUTTON FUNCTIONALITY ===
    const likeButtons = document.querySelectorAll('.buttons button:nth-child(1)'); // first button = Like
    likeButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            if(btn.classList.contains('liked')){
                btn.classList.remove('liked');
                btn.textContent = 'Like';
            } else {
                btn.classList.add('liked');
                btn.textContent = 'Liked';
            }
        });
    });

    // === SHARE BUTTON FUNCTIONALITY ===
    const shareButtons = document.querySelectorAll('.buttons button:nth-child(2)'); // second button = Share
    shareButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const memeCard = btn.closest('.meme-card');
            const media = memeCard.querySelector('img, video');
            if(media){
                const url = window.location.origin + '/' + media.getAttribute('src');
                navigator.clipboard.writeText(url).then(() => {
                    alert('Meme link copied to clipboard!');
                });
            }
        });
    });

    // === SIMPLE HOVER ANIMATION ===
    const memeCards = document.querySelectorAll('.meme-card');
    memeCards.forEach(card => {
        card.addEventListener('mouseenter', () => {
            card.style.transform = 'scale(1.02)';
            card.style.transition = 'transform 0.2s';
        });
        card.addEventListener('mouseleave', () => {
            card.style.transform = 'scale(1)';
        });
    });

    // === OPTIONAL: MEME STORY PLAYBACK ===
    // If you implement sequential memes, add class="story" to container
    const stories = document.querySelectorAll('.story');
    stories.forEach(story => {
        const slides = story.querySelectorAll('.meme-card');
        let current = 0;
        setInterval(() => {
            slides.forEach((slide, i) => slide.style.display = i === current ? 'block' : 'none');
            current = (current + 1) % slides.length;
        }, 3000); // change every 3 seconds
    });

});