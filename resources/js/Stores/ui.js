import { defineStore } from 'pinia';

export const useUiStore = defineStore('ui', {
    state: () => ({ sidebarOpen: false, toasts: [], nextId: 1 }),
    actions: {
        toggleSidebar() { this.sidebarOpen = !this.sidebarOpen; },
        closeSidebar() { this.sidebarOpen = false; },
        notify(type, message, timeout = 5000) {
            if (!message) return;
            const id = this.nextId++;
            this.toasts.push({ id, type, message });
            if (timeout) setTimeout(() => this.dismiss(id), timeout);
        },
        success(message) { this.notify('success', message); },
        error(message) { this.notify('error', message, 8000); },
        dismiss(id) { this.toasts = this.toasts.filter((t) => t.id !== id); },
    },
});
