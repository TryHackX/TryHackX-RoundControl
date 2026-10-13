<?php
declare(strict_types=1);

use function App\I18n\h;

/**
 * Stopka — jedna dla portalu i panelu, jak w panelu trackera ($footerInPanel).
 *
 * Nazwa, autor i odnośnik do repozytorium wszędzie; NUMER WERSJI wyłącznie w panelu administratora. Strona publiczna
 * (logowanie, rejestracja) nie podaje wersji — numer wydania mówi odwiedzającemu, których opublikowanych błędów szukać,
 * a administrator i tak widzi go w panelu.
 *
 * @var bool $footerInPanel
 */
$footerInPanel = !empty($footerInPanel);
$repo = 'https://github.com/TryHackX/TryHackX-RoundControl';
$gh   = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/></svg>';
?>
<footer class="app-footer<?= $footerInPanel ? ' app-footer--panel' : '' ?>">
    <p>
        <span class="app-footer-name">TryHackX RoundControl</span>
        <span class="app-footer-sep" aria-hidden="true">&bull;</span>
        <span><?= h('common.footer.author') ?> <a href="https://github.com/TryHackX" target="_blank" rel="noopener noreferrer">TryHackX</a></span>
        <span class="app-footer-sep" aria-hidden="true">&bull;</span>
        <a href="<?= $repo ?>" target="_blank" rel="noopener noreferrer"><?= $gh ?> GitHub</a>
        <span class="app-footer-sep" aria-hidden="true">&bull;</span>
        <a href="<?= $repo ?>/blob/main/LICENSE" target="_blank" rel="noopener noreferrer"><?= h('common.footer.license') ?></a>
        <?php if ($footerInPanel && defined('APP_VERSION')): ?>
            <span class="app-footer-sep" aria-hidden="true">&bull;</span>
            <span class="app-footer-version"><?= h('common.footer.version', ['version' => APP_VERSION]) ?></span>
        <?php endif; ?>
    </p>
</footer>
