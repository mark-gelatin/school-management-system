<?php
/**
 * Shared Teacher Sidebar Styles
 * Include this in the <head> section of all teacher pages
 */
?>
<style>
/* Prevent body scroll when sidebar is open on mobile */
body.sidebar-open {
    overflow: hidden;
    position: fixed;
    width: 100%;
    height: 100%;
}

@media (min-width: 769px) {
    body.sidebar-open {
        overflow: visible;
        position: static;
        width: auto;
        height: auto;
    }
}

/* Sidebar */
.sidebar {
    width: 300px;
    background: linear-gradient(180deg, #a11c27 0%, #b31310 100%);
    height: 100vh;
    padding-top: 25px;
    color: white;
    position: fixed;
    left: 0;
    top: 0;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    z-index: 1000;
    transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                opacity 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                visibility 0.35s,
                width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    transform: translateX(0);
    opacity: 1;
    visibility: visible;
}

.sidebar.hidden {
    transform: translateX(-100%);
    opacity: 0;
    visibility: hidden;
}

.sidebar.active {
    transform: translateX(0);
    opacity: 1;
    visibility: visible;
    z-index: 1001;
}

.logo {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 15px;
    padding: 0 20px 15px 20px;
    position: relative;
    min-width: 0;
    flex-shrink: 0;
}

.logo::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 20px;
    right: 20px;
    height: 2px;
    background: rgba(255,255,255,0.3);
}

.logo img {
    width: auto;
    height: 50px;
    object-fit: contain;
    flex-shrink: 0;
    max-width: 50px;
    display: block;
}

.school-name {
    font-size: 1.1rem;
    font-weight: 700;
    color: white;
    line-height: 1.3;
    text-align: left;
    white-space: nowrap;
    overflow: visible;
    text-overflow: clip;
    min-width: 0;
    flex: 1;
    word-break: keep-all;
    letter-spacing: 0.3px;
    display: block;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}

.nav-section {
    margin-bottom: 25px;
}

.nav-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 20px;
    margin-bottom: 2px;
    border-radius: 0;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    color: white;
    position: relative;
}

.nav-item:hover {
    background: rgba(255,255,255,0.08);
}

.nav-item.active {
    background: rgba(255,255,255,0.15);
}

.nav-item i {
    width: 18px;
    text-align: center;
    font-size: 1rem;
    flex-shrink: 0;
}

.nav-item span:not(.nav-badge) {
    flex: 1;
    font-size: 0.95rem;
}

.nav-badge {
    margin-left: auto;
    background: rgba(255,255,255,0.25);
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
    min-width: 20px;
    text-align: center;
    flex-shrink: 0;
}

.sidebar-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    padding-bottom: 20px;
}

.sidebar .user-profile {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 20px 15px;
    background: rgba(255,255,255,0.1);
    border-radius: 12px;
    margin: 0 15px 20px 15px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.sidebar .profile-picture {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: white;
    border: 3px solid rgba(255,255,255,0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    color: #a11c27;
    margin-bottom: 12px;
    flex-shrink: 0;
    font-weight: 700;
}

.sidebar .user-name {
    font-size: 1.1rem;
    font-weight: 700;
    margin-bottom: 5px;
    text-align: center;
    color: white;
}

.sidebar .user-role {
    font-size: 0.85rem;
    opacity: 0.9;
    text-align: center;
    color: rgba(255,255,255,0.95);
    font-weight: 500;
}

.sidebar-footer {
    flex-shrink: 0;
    padding-top: 15px;
    border-top: 1px solid rgba(255,255,255,0.1);
}

.upgrade-btn {
    background: white;
    color: #a11c27;
    border: none;
    padding: 9px 18px;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    width: 100%;
    font-size: 0.9rem;
    transition: background 0.2s, color 0.2s;
}

.upgrade-btn:hover {
    background: #f5f5f5;
}

/* Main Content - Support both .main-content and .container for backward compatibility */
.main-content,
.container {
    margin-left: 300px;
    flex: 1;
    padding: 30px;
    width: calc(100% - 300px);
    max-width: 100%;
    transition: margin-left 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
}

.main-content.expanded,
.container.expanded {
    margin-left: 0;
    width: 100%;
}

/* Mobile Menu Toggle */
.mobile-menu-toggle {
    display: none;
    position: fixed;
    top: 16px;
    left: 16px;
    z-index: 1001;
    background: #a11c27;
    color: white;
    border: none;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 1rem;
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    transition: all 0.3s ease;
    width: auto;
    height: auto;
    min-width: 40px;
    min-height: 40px;
    align-items: center;
    justify-content: center;
}

.mobile-menu-toggle:not(.hide) {
    display: flex;
}

.mobile-menu-toggle.hide {
    display: none !important;
}

.mobile-menu-toggle:hover {
    background: #b31310;
    transform: scale(1.05);
    box-shadow: 0 3px 12px rgba(0,0,0,0.2);
}

.mobile-menu-toggle:active {
    transform: scale(0.95);
}

.sidebar-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 999;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                visibility 0.35s;
    cursor: pointer;
    backdrop-filter: blur(2px);
    -webkit-backdrop-filter: blur(2px);
}

.sidebar-overlay.active {
    display: block;
    opacity: 1;
    visibility: visible;
}

@media (max-width: 1024px) {
    .sidebar {
        width: 280px;
    }
    
    .main-content,
    .container {
        margin-left: 280px;
        width: calc(100% - 280px);
        padding: 20px;
    }
    
    .school-name {
        font-size: 1rem;
    }
}

@media (max-width: 768px) {
    .mobile-menu-toggle {
        display: block;
    }
    
    .sidebar {
        width: 280px;
        transform: translateX(-100%);
        transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                    opacity 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                    visibility 0.35s;
        position: fixed;
        z-index: 1000;
        opacity: 0;
        visibility: hidden;
    }
    
    .sidebar.active {
        transform: translateX(0);
        opacity: 1;
        visibility: visible;
        z-index: 1001;
    }
    
    .sidebar.hidden {
        transform: translateX(-100%);
        opacity: 0;
        visibility: hidden;
    }
    
    .main-content,
    .container {
        margin-left: 0;
        padding: 15px;
        padding-top: 70px;
        width: 100%;
        transition: margin-left 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                    padding-top 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    .school-name {
        font-size: 1rem;
    }
    
    body.sidebar-open {
        overflow: hidden;
        position: fixed;
        width: 100%;
        transition: none;
    }
}

@media (min-width: 769px) {
    body.sidebar-open {
        overflow: visible;
        position: static;
        width: auto;
        height: auto;
    }
}

@media (max-width: 480px) {
    .sidebar {
        width: 100%;
        max-width: 280px;
    }
    
    .main-content,
    .container {
        padding: 10px;
        padding-top: 70px;
    }
    
    .mobile-menu-toggle {
        padding: 7px 10px;
        font-size: 0.9rem;
        min-width: 36px;
        min-height: 36px;
        top: 12px;
        left: 12px;
    }
    
    .school-name {
        font-size: 0.95rem;
    }
    
    .logo {
        padding: 0 15px 15px 15px;
    }
    
    .logo img {
        height: 45px;
        max-width: 45px;
    }
}

/* Ensure title is always visible - additional safety */
@media (max-width: 360px) {
    .school-name {
        font-size: 0.9rem;
        letter-spacing: 0.2px;
    }
    
    .logo {
        gap: 10px;
        padding: 0 12px 15px 12px;
    }
    
    .logo img {
        height: 40px;
        max-width: 40px;
    }
}
</style>
