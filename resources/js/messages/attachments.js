import { validateCommunityFiles } from '../community/files.js';

// Use the actual limits supplied by the server. No inline preview/object URL.
export function validateAttachment(file, existing, rules) {
    if (!rules) return validateDemoAttachment(file, existing);
    const error = validateCommunityFiles([file], rules, existing.length)[0];
    if (error) return error;
    if (existing.some(item => item.name === file.name && item.size === file.size && item.lastModified === file.lastModified)) return 'Este archivo ya está añadido.';
    return null;
}
export function formatBytes(bytes) {
    return bytes < 1024 ** 2 ? Math.max(1, Math.round(bytes / 1024)) + ' KB' : (bytes / 1024 ** 2).toFixed(1) + ' MB';
}

// Compatibility for the separate, still-local LEXA demo. Messaging never imports
// these functions and never creates previews for private uploads or downloads.
const demoFormats = { jpg:['image','image/jpeg'], jpeg:['image','image/jpeg'], png:['image','image/png'], webp:['image','image/webp'],
    pdf:['document','application/pdf'], docx:['document','application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    mp4:['video','video/mp4'], webm:['video','video/webm'] };
function validateDemoAttachment(file, existing) {
    const format = demoFormats[file.name.split('.').pop().toLowerCase()];
    if (!format || (file.type && file.type !== format[1])) return 'Formato no admitido.';
    const limit = { image:10, document:20, video:50 }[format[0]];
    if (!file.size || file.size > limit * 1024 ** 2) return `Límite: ${limit} MB por archivo de este tipo.`;
    if (existing.length >= 4) return 'Puedes adjuntar hasta 4 archivos.';
    if (existing.reduce((sum,item) => sum + item.size,0) + file.size > 80 * 1024 ** 2) return 'El total no puede superar 80 MB.';
    if (existing.some(item => item.name === file.name && item.size === file.size && item.lastModified === file.lastModified)) return 'Este archivo ya está añadido.';
    return null;
}
export function createAttachment(file) {
    const [kind,mime] = demoFormats[file.name.split('.').pop().toLowerCase()];
    return { id:crypto.randomUUID(), name:file.name, size:file.size, lastModified:file.lastModified, kind, mime, url:URL.createObjectURL(file), local:true };
}
export function releaseAttachment(attachment) {
    if (attachment.local) URL.revokeObjectURL(attachment.url);
}
