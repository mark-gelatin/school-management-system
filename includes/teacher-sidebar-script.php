<?php
/**
 * Shared Teacher Sidebar JavaScript
 * Include this before closing </body> tag on all teacher pages
 * Includes state preservation via localStorage
 */
?>
<script>
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
    
    // Unified Sidebar Toggle Functions - Used across all teacher pages
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const toggleBtn = document.getElementById('mobileMenuToggle');
        // Support both .main-content and .container for backward compatibility
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
                // Desktop: collapse sidebar, show toggle button
                sidebar.classList.add('hidden');
                if (mainContent) mainContent.classList.add('expanded');
                if (toggleBtn) {
                    // Only show toggle button on desktop when sidebar is hidden
                    if (window.innerWidth > 768) {
                        toggleBtn.style.display = 'block';
                    } else {
                        // On mobile, let CSS handle it
                        toggleBtn.style.display = '';
                    }
                    toggleBtn.classList.remove('hide');
                }
                document.body.classList.remove('sidebar-open');
                
                // Save state
                saveSidebarState({ isHidden: true, isActive: false });
            }
        }
    }
    
    function hideSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const toggleBtn = document.getElementById('mobileMenuToggle');
        // Support both .main-content and .container for backward compatibility
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
    
    // Initialize sidebar on page load
    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const toggleBtn = document.getElementById('mobileMenuToggle');
        // Support both .main-content and .container for backward compatibility
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
                if (toggleBtn) {
                    // Show toggle button on desktop when sidebar is hidden
                    if (window.innerWidth > 768) {
                        toggleBtn.style.display = 'block';
                    } else {
                        // On mobile, let CSS handle it
                        toggleBtn.style.display = '';
                    }
                    toggleBtn.classList.remove('hide');
                }
            } else {
                sidebar.classList.remove('hidden');
                sidebar.classList.remove('active');
                if (mainContent) mainContent.classList.remove('expanded');
                if (toggleBtn) toggleBtn.style.display = 'none';
            }
        } else {
            // Default initialization based on screen size
            if (isMobile) {
                // Mobile: sidebar hidden by default, toggle button visible
                sidebar.classList.add('hidden');
                sidebar.classList.remove('active');
                if (mainContent) mainContent.classList.add('expanded');
                if (toggleBtn) {
                    // Don't set display style - let CSS handle it
                    toggleBtn.classList.remove('hide');
                    // Ensure it's visible on mobile
                    toggleBtn.style.display = '';
                }
            } else {
                // Desktop: sidebar visible by default, toggle button hidden
                sidebar.classList.remove('hidden');
                sidebar.classList.remove('active');
                if (mainContent) mainContent.classList.remove('expanded');
                if (toggleBtn) {
                    toggleBtn.style.display = 'none';
                    toggleBtn.classList.remove('hide');
                }
            }
        }
        
        // Close sidebar when clicking nav items on mobile
        navItems.forEach(item => {
            item.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    hideSidebar();
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
                    if (toggleBtn) {
                    // Show toggle button on desktop when sidebar is hidden
                    if (window.innerWidth > 768) {
                        toggleBtn.style.display = 'block';
                    } else {
                        // On mobile, let CSS handle it
                        toggleBtn.style.display = '';
                    }
                    toggleBtn.classList.remove('hide');
                }
                    saveSidebarState({ isHidden: true, isActive: false });
                }
            } else if (sidebar && sidebar.classList.contains('active')) {
                if (window.innerWidth <= 768) {
                    hideSidebar();
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
                    // On mobile, CSS handles display via media query
                    // Just ensure hide class is removed
                    toggleBtn.classList.remove('hide');
                    // Clear any inline display style to let CSS take over
                    toggleBtn.style.display = '';
                }
                document.body.classList.remove('sidebar-open');
            } else {
                // Switched to desktop - restore saved state
                const savedState = getSidebarState();
                if (sidebar && !sidebar.classList.contains('hidden')) {
                    sidebar.classList.remove('active');
                    if (toggleBtn) {
                        toggleBtn.style.display = 'none';
                        toggleBtn.classList.remove('hide');
                    }
                } else if (sidebar && sidebar.classList.contains('hidden')) {
                    if (toggleBtn) {
                        // Show toggle button on desktop when sidebar is hidden
                        if (window.innerWidth > 768) {
                            toggleBtn.style.display = 'block';
                        } else {
                            // On mobile, let CSS handle it
                            toggleBtn.style.display = '';
                        }
                        toggleBtn.classList.remove('hide');
                    }
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
</script>
