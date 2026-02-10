/**
 * Standardized Teacher Sidebar Toggle Script
 * Use this script on all teacher pages for consistent sidebar behavior
 * Includes state preservation via localStorage
 */

(function() {
    'use strict';
    
    // Sidebar state management with localStorage
    const STORAGE_KEY = 'teacher_sidebar_state';
    
    function getSidebarState() {
        try {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved) {
                return JSON.parse(saved);
            }
        } catch (e) {
            console.warn('Could not read sidebar state from localStorage:', e);
        }
        return null;
    }
    
    function saveSidebarState(state) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (e) {
            console.warn('Could not save sidebar state to localStorage:', e);
        }
    }
    
    // Sidebar toggle functions
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const toggleBtn = document.getElementById('mobileMenuToggle');
        const mainContent = document.querySelector('.main-content') || document.querySelector('.container');
        
        if (!sidebar) return;
        
        const isHidden = sidebar.classList.contains('hidden');
        const isActive = sidebar.classList.contains('active');
        const isMobile = window.innerWidth <= 768;
        
        if (isHidden) {
            // Show sidebar
            sidebar.classList.remove('hidden');
            if (isMobile) {
                sidebar.classList.add('active');
                if (overlay) overlay.classList.add('active');
                if (toggleBtn) toggleBtn.classList.add('hide');
                document.body.classList.add('sidebar-open');
            } else {
                if (toggleBtn) toggleBtn.style.display = 'none';
            }
            if (mainContent) mainContent.classList.remove('expanded');
            
            // Save state
            saveSidebarState({ isHidden: false, isActive: isMobile });
        } else {
            // Sidebar is visible, toggle it
            if (isMobile) {
                const newActiveState = !isActive;
                sidebar.classList.toggle('active', newActiveState);
                if (overlay) overlay.classList.toggle('active', newActiveState);
                if (toggleBtn) toggleBtn.classList.toggle('hide', newActiveState);
                if (mainContent) {
                    if (newActiveState) {
                        mainContent.classList.remove('expanded');
                    } else {
                        mainContent.classList.add('expanded');
                    }
                }
                if (newActiveState) {
                    document.body.classList.add('sidebar-open');
                } else {
                    document.body.classList.remove('sidebar-open');
                }
                
                // Save state
                saveSidebarState({ isHidden: false, isActive: newActiveState });
            } else {
                sidebar.classList.add('hidden');
                if (mainContent) mainContent.classList.add('expanded');
                if (toggleBtn) toggleBtn.style.display = 'block';
                
                // Save state
                saveSidebarState({ isHidden: true, isActive: false });
            }
        }
    }
    
    function hideSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const toggleBtn = document.getElementById('mobileMenuToggle');
        const mainContent = document.querySelector('.main-content') || document.querySelector('.container');
        
        if (sidebar) {
            sidebar.classList.remove('active');
            sidebar.classList.add('hidden');
            if (overlay) overlay.classList.remove('active');
            if (mainContent) mainContent.classList.add('expanded');
            if (toggleBtn && window.innerWidth <= 768) {
                toggleBtn.classList.remove('hide');
            }
            document.body.classList.remove('sidebar-open');
            
            // Save state
            saveSidebarState({ isHidden: true, isActive: false });
        }
    }
    
    // Make functions globally available
    window.toggleSidebar = toggleSidebar;
    window.hideSidebar = hideSidebar;
    
    // Initialize sidebar state on page load
    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const toggleBtn = document.getElementById('mobileMenuToggle');
        const mainContent = document.querySelector('.main-content') || document.querySelector('.container');
        const navItems = document.querySelectorAll('.nav-item');
        
        if (!sidebar) return;
        
        const isMobile = window.innerWidth <= 768;
        const savedState = getSidebarState();
        
        // Restore saved state if available and on desktop
        if (savedState && !isMobile) {
            if (savedState.isHidden) {
                sidebar.classList.add('hidden');
                sidebar.classList.remove('active');
                if (mainContent) mainContent.classList.add('expanded');
                if (toggleBtn) toggleBtn.style.display = 'block';
            } else {
                sidebar.classList.remove('hidden');
                sidebar.classList.remove('active');
                if (mainContent) mainContent.classList.remove('expanded');
                if (toggleBtn) toggleBtn.style.display = 'none';
            }
        } else {
            // Default initialization based on screen size
            if (isMobile) {
                // Mobile: sidebar hidden by default
                sidebar.classList.add('hidden');
                sidebar.classList.remove('active');
                if (mainContent) mainContent.classList.add('expanded');
                if (toggleBtn) {
                    toggleBtn.style.display = 'block';
                    toggleBtn.classList.remove('hide');
                }
            } else {
                // Desktop: sidebar visible by default
                sidebar.classList.remove('hidden');
                sidebar.classList.remove('active');
                if (mainContent) mainContent.classList.remove('expanded');
                if (toggleBtn) toggleBtn.style.display = 'none';
            }
        }
        
        // Close sidebar when clicking nav items on mobile
        navItems.forEach(item => {
            item.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    if (sidebar) {
                        sidebar.classList.remove('active');
                        sidebar.classList.add('hidden');
                    }
                    if (overlay) overlay.classList.remove('active');
                    if (mainContent) mainContent.classList.add('expanded');
                    if (toggleBtn) toggleBtn.classList.remove('hide');
                    document.body.classList.remove('sidebar-open');
                }
            });
        });
        
        // Hide sidebar when clicking outside (desktop)
        document.addEventListener('click', function(event) {
            if (sidebar && sidebar.contains(event.target)) return;
            if (toggleBtn && (toggleBtn.contains(event.target) || toggleBtn === event.target)) return;
            if (overlay && event.target === overlay) return;
            
            if (sidebar && !sidebar.classList.contains('hidden') && !sidebar.classList.contains('active')) {
                if (window.innerWidth > 768) {
                    sidebar.classList.add('hidden');
                    if (mainContent) mainContent.classList.add('expanded');
                    if (toggleBtn) toggleBtn.style.display = 'block';
                    saveSidebarState({ isHidden: true, isActive: false });
                }
            } else if (sidebar && sidebar.classList.contains('active')) {
                if (window.innerWidth <= 768) {
                    sidebar.classList.remove('active');
                    if (overlay) overlay.classList.remove('active');
                    if (mainContent) mainContent.classList.add('expanded');
                    if (toggleBtn) toggleBtn.classList.remove('hide');
                    document.body.classList.remove('sidebar-open');
                }
            }
        });
        
        // Handle window resize
        window.addEventListener('resize', function() {
            const isMobileNow = window.innerWidth <= 768;
            
            if (isMobileNow) {
                // Switched to mobile
                if (sidebar) {
                    sidebar.classList.add('hidden');
                    sidebar.classList.remove('active');
                }
                if (overlay) overlay.classList.remove('active');
                if (mainContent) mainContent.classList.add('expanded');
                if (toggleBtn) {
                    toggleBtn.style.display = 'block';
                    toggleBtn.classList.remove('hide');
                }
                document.body.classList.remove('sidebar-open');
            } else {
                // Switched to desktop
                const savedState = getSidebarState();
                if (sidebar && !sidebar.classList.contains('hidden')) {
                    sidebar.classList.remove('active');
                    if (toggleBtn) toggleBtn.style.display = 'none';
                } else if (sidebar && sidebar.classList.contains('hidden')) {
                    if (toggleBtn) toggleBtn.style.display = 'block';
                }
                if (overlay) overlay.classList.remove('active');
                if (mainContent && sidebar && !sidebar.classList.contains('hidden')) {
                    mainContent.classList.remove('expanded');
                }
                document.body.classList.remove('sidebar-open');
            }
        });
    });
})();
