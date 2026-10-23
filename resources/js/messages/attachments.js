const formats = {
    jpg: ['image', 'image/jpeg'], jpeg: ['image', 'image/jpeg'], png: ['image', 'image/png'], webp: ['image', 'image/webp'],
    pdf: ['document', 'application/pdf'], docx: ['document', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    mp4: ['video', 'video/mp4'], webm: ['video', 'video/webm'],
};
const limits = { image: 10, document: 20, video: 50 };
export function validateAttachment(file, existing) {
    const extension = file.name.split('.').pop().toLowerCase();
    const format = formats[extension];
    if (!format || (file.type && file.type !== format[1])) return 'Formato no admitido.';
    if (!file.size || file.size > limits[format[0]] * 1024 ** 2) return 'Límite: ' + limits[format[0]] + ' MB por archivo de este tipo.';
    if (existing.length >= 4) return 'Puedes adjuntar hasta 4 archivos.';
    if (existing.reduce((sum, item) => sum + item.size, 0) + file.size > 80 * 1024 ** 2) return 'El total no puede superar 80 MB.';
    if (existing.some((item) => item.name === file.name && item.size === file.size && item.lastModified === file.lastModified)) return 'Este archivo ya está añadido.';
    return null;
}
export function createAttachment(file) {
    const [kind, mime] = formats[file.name.split('.').pop().toLowerCase()];
    return { id: crypto.randomUUID(), name: file.name, size: file.size, lastModified: file.lastModified, kind, mime, url: URL.createObjectURL(file), local: true };
}
export function formatBytes(bytes) {
    return bytes < 1024 ** 2 ? Math.max(1, Math.round(bytes / 1024)) + ' KB' : (bytes / 1024 ** 2).toFixed(1) + ' MB';
}
export function releaseAttachment(attachment) {
    if (attachment.local) URL.revokeObjectURL(attachment.url);
}
