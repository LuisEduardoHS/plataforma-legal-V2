// This only provides early feedback. Fileinfo and domain authorization remain
// authoritative on the server; no object URLs or inline private previews exist.
export function validateCommunityFiles(files, rules, retained = 0) {
    const errors = [];
    if (files.length + retained > rules.maxCount) errors.push(`Selecciona hasta ${rules.maxCount} archivos en total.`);
    for (const file of files) {
        const extension = file.name.split('.').pop().toLowerCase();
        const matches = Object.entries(rules.types).filter(([, type]) => type.extensions.includes(extension));
        const type = matches.find(([mime]) => !file.type || file.type === mime)?.[1];
        if (!type) errors.push(`${file.name}: formato no admitido.`);
        else if (!file.size || file.size > rules.maxBytes[type.category]) errors.push(`${file.name}: tamaño no permitido.`);
    }
    return errors;
}
