export function validateImage(file, { maxKb = 512, allowedTypes = ['image/png', 'image/jpeg'] } = {}) {
    if (!file) {
        return { valid: false, error: 'No file selected.' };
    }
    if (!allowedTypes.includes(file.type)) {
        return { valid: false, error: `Only ${allowedTypes.join(', ')} are accepted.` };
    }
    if (file.size > maxKb * 1024) {
        return { valid: false, error: `File must be smaller than ${maxKb}KB.` };
    }
    return { valid: true };
}