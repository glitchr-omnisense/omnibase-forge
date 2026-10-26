/*
 * A game played in its page (omnibase/forge, templates/client/downloads/_game.html.twig): the
 * Play link frames the WebGL build (forge_play) inside the stage, in place of opening it on its
 * own - nothing of the game is loaded before it is pressed; the full-screen button then puts the
 * frame full screen. Delegated from the document: a page swapped in without a reload is served too.
 */
(function () {
    'use strict';

    if (window.ForgePlay) return;

    function start(link) {
        const play = link.closest('[data-forge-play]');
        const stage = play && play.querySelector('[data-forge-play-stage]');
        if (!stage) return false;

        const frame = document.createElement('iframe');
        frame.src = link.href;
        frame.title = link.textContent.trim();
        frame.allow = 'fullscreen; autoplay; gamepad';
        frame.setAttribute('allowfullscreen', '');
        stage.replaceChildren(frame);
        stage.classList.add('is-playing');
        frame.focus();

        const fullscreen = play.querySelector('[data-forge-play-fullscreen]');
        if (fullscreen && frame.requestFullscreen) fullscreen.hidden = false;

        return true;
    }

    document.addEventListener('click', (event) => {
        const link = event.target.closest && event.target.closest('[data-forge-play-start]');
        if (link && link.href && !event.metaKey && !event.ctrlKey && !event.shiftKey && start(link)) {
            event.preventDefault();
            event.stopPropagation();

            return;
        }

        const fullscreen = event.target.closest && event.target.closest('[data-forge-play-fullscreen]');
        const frame = fullscreen && fullscreen.closest('[data-forge-play]').querySelector('iframe');
        if (frame && frame.requestFullscreen) frame.requestFullscreen();
    }, true);

    window.ForgePlay = { start };
})();
