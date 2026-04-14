/**
 * Debounce function to limit execution frequency
 * @param {Function} callback - Function to debounce
 * @param {number} wait - Wait time in milliseconds (default: 300)
 * @returns {Function} Debounced function
 */
export function debounce(callback, wait = 300) {
    let timerId;
    return (...args) => {
        clearTimeout(timerId);
        timerId = setTimeout(() => callback(...args), wait);
    };
}
