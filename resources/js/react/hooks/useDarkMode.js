import { useState, useEffect } from 'react';

/**
 * Tracks whether Filament's dark mode is active.
 *
 * Filament toggles the `dark` class on <html> when the user switches theme.
 * We observe that class with a MutationObserver so React components re-render
 * automatically when the theme changes without a page reload.
 */
export function useDarkMode() {
    const [isDark, setIsDark] = useState(
        () => document.documentElement.classList.contains('dark'),
    );

    useEffect(() => {
        const observer = new MutationObserver(() => {
            setIsDark(document.documentElement.classList.contains('dark'));
        });

        observer.observe(document.documentElement, {
            attributes:      true,
            attributeFilter: ['class'],
        });

        return () => observer.disconnect();
    }, []);

    return isDark;
}
