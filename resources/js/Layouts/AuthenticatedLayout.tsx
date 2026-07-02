import { Link, usePage, router } from '@inertiajs/react';
import { PropsWithChildren, ReactNode, useState, useEffect } from 'react';
import ImpersonationBar from '@/Components/SpendifiAI/ImpersonationBar';
import {
  LayoutDashboard,
  Receipt,
  CreditCard,
  PiggyBank,
  FileText,
  Archive,
  Link2,
  Settings,
  HelpCircle,
  Bell,
  LogOut,
  ChevronLeft,
  ChevronRight,
  Menu,
  X,
  ShieldCheck,
  Mail,
  ChevronDown,
  ChevronUp,
  Users,
  TrendingUp,
  Building2,
  Heart,
  HardDrive,
  Cookie,
} from 'lucide-react';

interface NavItemDef {
  label: string;
  href: string;
  routeName: string;
  icon: ReactNode;
  badge?: number;
}

function NavItem({
  item,
  active,
  collapsed,
  variant = 'default',
}: {
  item: NavItemDef;
  active: boolean;
  collapsed: boolean;
  variant?: 'default' | 'admin';
}) {
  const adminActive = variant === 'admin' && active;
  const adminInactive = variant === 'admin' && !active;

  return (
    <Link
      href={item.href}
      className={`relative flex items-center gap-3 w-full px-4 py-2.5 rounded-lg text-sm font-medium ${
        adminActive
          ? "bg-sw-warning/15 text-sw-warning before:content-[''] before:absolute before:left-0 before:top-1/2 before:-translate-y-1/2 before:h-5 before:w-[3px] before:rounded-full before:bg-gradient-to-b before:from-sw-warning before:to-amber-500"
          : adminInactive
          ? 'text-amber-700/70 hover:text-amber-800 hover:bg-sw-warning/10 transition-all [transition-duration:150ms] [transition-timing-function:cubic-bezier(0.25,0.46,0.45,0.94)]'
          : active
          ? "bg-gradient-to-r from-sw-accent/12 to-transparent text-sw-accent before:content-[''] before:absolute before:left-0 before:top-1/2 before:-translate-y-1/2 before:h-5 before:w-[3px] before:rounded-full before:bg-gradient-to-b before:from-sw-accent before:to-violet-600"
          : 'text-sw-muted hover:text-sw-text hover:bg-sw-surface/80 transition-all [transition-duration:150ms] [transition-timing-function:cubic-bezier(0.25,0.46,0.45,0.94)]'
      }`}
    >
      <span className="shrink-0">{item.icon}</span>
      {!collapsed && <span>{item.label}</span>}
      {!collapsed && item.badge !== undefined && item.badge > 0 && (
        <span className="ml-auto bg-sw-danger text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full min-w-[18px] text-center">
          {item.badge}
        </span>
      )}
    </Link>
  );
}

export default function AuthenticatedLayout({
  header,
  children,
}: PropsWithChildren<{ header?: ReactNode }>) {
  const page = usePage();
  const auth = page.props.auth as Record<string, unknown>;
  const user = auth.user as { name: string; email: string };
  const isAdmin = auth.isAdmin as boolean;
  const isAccountant = auth.isAccountant as boolean;
  const hasBankConnected = auth.hasBankConnected as boolean;
  const hasEmailConnected = auth.hasEmailConnected as boolean;
  const currentRoute = (page.props as Record<string, unknown>).ziggy
    ? route().current()
    : '';

  const [collapsed, setCollapsed] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [userMenuOpen, setUserMenuOpen] = useState(false);
  const [emailBannerDismissed, setEmailBannerDismissed] = useState(true);
  const [emailBannerExpanded, setEmailBannerExpanded] = useState(false);
  const [adminExpanded, setAdminExpanded] = useState(false);

  useEffect(() => {
    setEmailBannerDismissed(localStorage.getItem('emailBannerDismissed') === '1');
    // Persist admin group expansion like dark-mode toggle pattern
    setAdminExpanded(localStorage.getItem('adminNavExpanded') === '1');
  }, []);

  const toggleAdminExpanded = () => {
    const next = !adminExpanded;
    setAdminExpanded(next);
    localStorage.setItem('adminNavExpanded', next ? '1' : '0');
  };

  const showEmailBanner = hasBankConnected && !hasEmailConnected && !emailBannerDismissed;

  const dismissEmailBanner = () => {
    localStorage.setItem('emailBannerDismissed', '1');
    setEmailBannerDismissed(true);
  };

  // Main nav items — Settings and My Profile are NOT here; they are in the bottom-pinned group.
  // Admin items are NOT here; they are in the collapsible admin group below.
  const mainNavItems: NavItemDef[] = [
    { label: 'Dashboard', href: '/dashboard', routeName: 'dashboard', icon: <LayoutDashboard size={18} /> },
    { label: 'Transactions', href: '/transactions', routeName: 'transactions', icon: <Receipt size={18} /> },
    { label: 'Subscriptions', href: '/subscriptions', routeName: 'subscriptions', icon: <CreditCard size={18} /> },
    { label: 'Savings', href: '/savings', routeName: 'savings', icon: <PiggyBank size={18} /> },
    { label: 'Tax', href: '/tax', routeName: 'tax', icon: <FileText size={18} /> },
    { label: 'Tax Vault', href: '/vault', routeName: 'vault', icon: <Archive size={18} /> },
    { label: 'Connect', href: '/connect', routeName: 'connect', icon: <Link2 size={18} /> },
    { label: 'AI Questions', href: '/questions', routeName: 'questions', icon: <HelpCircle size={18} /> },
    { label: 'Optimize My Income', href: '/optimize', routeName: 'optimize', icon: <TrendingUp size={18} />, badge: (((auth.pendingOptimizationCount as number) || 0) + ((auth.pendingActionCount as number) || 0)) || undefined },
    ...(isAccountant ? [
      { label: 'Clients', href: '/accountant/clients', routeName: 'accountant.clients', icon: <Users size={18} /> },
    ] : []),
  ];

  // Admin nav items — all admin routes collected here, never mixed into user nav.
  const adminNavItems: NavItemDef[] = [
    { label: 'Admin Dashboard', href: '/admin', routeName: 'admin.dashboard', icon: <ShieldCheck size={16} /> },
    { label: 'Providers', href: '/admin/providers', routeName: 'admin.providers', icon: <Building2 size={16} /> },
    { label: 'Charities', href: '/admin/charities', routeName: 'admin.charities', icon: <Heart size={16} /> },
    { label: 'Storage', href: '/admin/storage', routeName: 'admin.storage', icon: <HardDrive size={16} /> },
    { label: 'Consent', href: '/admin/consent', routeName: 'admin.consent', icon: <Cookie size={16} /> },
  ];

  // Profile & Settings — pinned to very bottom of sidebar (below admin group).
  const profileSettingsItem: NavItemDef = {
    label: 'Profile & Settings',
    href: '/settings',
    routeName: 'settings',
    icon: <Settings size={18} />,
  };

  const isActive = (routeName: string) => {
    try {
      return route().current(routeName);
    } catch {
      return currentRoute === routeName;
    }
  };

  // Check if any admin route is currently active (for collapsed indicator)
  const isAnyAdminActive = adminNavItems.some(item => !!isActive(item.routeName));

  const sidebarContent = (
    <>
      {/* Logo */}
      <div className="flex items-center gap-3 px-5 py-5 border-b border-sw-border">
        <Link href="/dashboard" className="flex items-center gap-2">
          <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" className="h-9 w-9 shrink-0">
            <rect width="40" height="40" rx="10" fill="url(#sidebar-logo-gradient)" />
            <path d="M8 28.5 Q20 31 32 28.5" stroke="white" strokeWidth="2.2" strokeLinecap="round" fill="none" />
            <rect x="12.5" y="22" width="3.5" height="6.5" rx="1.5" fill="white" fillOpacity="0.55" />
            <rect x="18" y="17" width="3.5" height="11.5" rx="1.5" fill="white" fillOpacity="0.75" />
            <rect x="23.5" y="12" width="3.5" height="16.5" rx="1.5" fill="white" />
            <circle cx="25.25" cy="9.5" r="1.6" fill="white" fillOpacity="0.9" />
            <line x1="25.25" y1="6" x2="25.25" y2="7.5" stroke="white" strokeWidth="1" strokeLinecap="round" opacity="0.7" />
            <line x1="22.5" y1="9.5" x2="23.2" y2="9.5" stroke="white" strokeWidth="1" strokeLinecap="round" opacity="0.7" />
            <line x1="27.3" y1="9.5" x2="28" y2="9.5" stroke="white" strokeWidth="1" strokeLinecap="round" opacity="0.7" />
            <defs>
              <linearGradient id="sidebar-logo-gradient" x1="0" y1="0" x2="40" y2="40" gradientUnits="userSpaceOnUse">
                <stop stopColor="#2563eb" />
                <stop offset="1" stopColor="#7c3aed" />
              </linearGradient>
            </defs>
          </svg>
          {!collapsed && (
            <div>
              <div className="text-[15px] font-bold text-sw-text tracking-tight">Spendifi<span className="text-sw-accent">AI</span></div>
              <div className="text-[10px] text-sw-dim font-medium tracking-wide">AI-Powered Financial Intelligence</div>
            </div>
          )}
        </Link>
      </div>

      {/* Main Nav */}
      <nav aria-label="Main navigation" className="flex-1 flex flex-col gap-1 px-3 py-4 overflow-y-auto">
        {mainNavItems.map((item) => (
          <NavItem
            key={item.routeName}
            item={item}
            active={!!isActive(item.routeName)}
            collapsed={collapsed}
          />
        ))}
      </nav>

      {/* Bottom-pinned section: Admin group (admins only) + Profile & Settings */}
      <div className="px-3 pt-2 pb-1 border-t border-sw-border space-y-1">

        {/* Admin group — rendered ONLY for admins, visually distinct, collapsible */}
        {isAdmin && (
          <div className="mb-1">
            {/* Admin group header — collapsed by default, amber/warning tone */}
            <button
              onClick={toggleAdminExpanded}
              aria-expanded={adminExpanded}
              className={`relative flex items-center gap-3 w-full px-4 py-2.5 rounded-lg text-xs font-semibold tracking-wide transition-colors ${
                isAnyAdminActive && !adminExpanded
                  ? 'bg-sw-warning/15 text-sw-warning'
                  : adminExpanded
                  ? 'bg-sw-warning/10 text-amber-700'
                  : 'text-amber-700/70 hover:bg-sw-warning/10 hover:text-amber-800'
              }`}
            >
              <span className="shrink-0">
                <ShieldCheck size={collapsed ? 18 : 16} />
              </span>
              {!collapsed && (
                <>
                  <span className="flex-1 text-left uppercase tracking-widest text-[10px]">Admin</span>
                  {adminExpanded ? <ChevronUp size={12} /> : <ChevronDown size={12} />}
                </>
              )}
              {/* Collapsed indicator dot when admin route is active */}
              {collapsed && isAnyAdminActive && (
                <span className="absolute top-2 right-2 w-1.5 h-1.5 rounded-full bg-sw-warning" />
              )}
            </button>

            {/* Admin items — shown when expanded (or when sidebar is collapsed and we show icon-only) */}
            {adminExpanded && !collapsed && (
              <div className="mt-0.5 space-y-0.5 pl-2">
                {adminNavItems.map((item) => (
                  <NavItem
                    key={item.routeName}
                    item={item}
                    active={!!isActive(item.routeName)}
                    collapsed={false}
                    variant="admin"
                  />
                ))}
              </div>
            )}
            {/* Collapsed sidebar: show admin items icon-only when expanded */}
            {adminExpanded && collapsed && (
              <div className="mt-0.5 space-y-0.5">
                {adminNavItems.map((item) => (
                  <NavItem
                    key={item.routeName}
                    item={item}
                    active={!!isActive(item.routeName)}
                    collapsed={true}
                    variant="admin"
                  />
                ))}
              </div>
            )}
          </div>
        )}

        {/* Profile & Settings — pinned at the very bottom, separated from user nav */}
        <NavItem
          item={profileSettingsItem}
          active={!!isActive(profileSettingsItem.routeName)}
          collapsed={collapsed}
        />
      </div>

      {/* Collapse toggle (desktop only) */}
      <div className="hidden sm:block border-t border-sw-border p-3">
        <button
          onClick={() => setCollapsed(!collapsed)}
          className="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-lg border border-sw-border bg-transparent text-sw-dim text-xs cursor-pointer hover:text-sw-muted transition"
        >
          {collapsed ? <ChevronRight size={14} /> : <><ChevronLeft size={14} /> Collapse</>}
        </button>
      </div>
    </>
  );

  return (
    <>
    <a href="#main-content" className="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:top-4 focus:left-4 focus:px-4 focus:py-2 focus:bg-sw-accent focus:text-white focus:rounded-lg focus:text-sm focus:font-semibold">Skip to main content</a>
    <div className="flex h-screen bg-sw-bg overflow-hidden">
      {/* Desktop sidebar */}
      <aside
        className={`hidden sm:flex flex-col bg-sw-sidebar border-r border-sw-card-frame shrink-0 [transition:width_220ms_cubic-bezier(0.32,0.72,0,1)] shadow-[1px_0_0_rgba(15,23,42,0.04)] ${
          collapsed ? 'w-[68px]' : 'w-64'
        }`}
      >
        {sidebarContent}
      </aside>

      {/* Mobile sidebar overlay */}
      {mobileOpen && (
        <div className="sm:hidden fixed inset-0 z-40">
          <div className="absolute inset-0 bg-black/20" onClick={() => setMobileOpen(false)} />
          <aside className="relative w-64 h-full bg-sw-sidebar border-r border-sw-border flex flex-col z-50">
            <button
              onClick={() => setMobileOpen(false)}
              aria-label="Close sidebar"
              className="absolute top-4 right-4 text-sw-muted hover:text-sw-text"
            >
              <X size={20} />
            </button>
            {sidebarContent}
          </aside>
        </div>
      )}

      {/* Main content area */}
      <div className="flex-1 flex flex-col overflow-hidden">
        {/* Top bar */}
        <header className="shrink-0 h-14 flex items-center justify-between px-6 border-b border-sw-border/60 bg-sw-card/90 backdrop-blur-sm shadow-[0_1px_0_rgba(15,23,42,0.06),0_2px_8px_rgba(15,23,42,0.04)]">
          <div className="flex items-center gap-4">
            {/* Mobile hamburger */}
            <button
              onClick={() => setMobileOpen(true)}
              aria-label="Open sidebar"
              aria-expanded={mobileOpen}
              className="sm:hidden text-sw-muted hover:text-sw-text"
            >
              <Menu size={20} />
            </button>

            {header && <div>{header}</div>}
          </div>

          <div className="flex items-center gap-3">
            {/* Notification bell */}
            <button aria-label="Notifications" className="relative w-9 h-9 rounded-xl border border-sw-border/80 bg-sw-surface/60 flex items-center justify-center text-sw-muted hover:text-sw-text hover:border-sw-border-strong hover:bg-sw-surface hover:shadow-sw-1 transition-all [transition-duration:150ms] [transition-timing-function:cubic-bezier(0.25,0.46,0.45,0.94)] active:scale-95">
              <Bell size={16} />
            </button>

            {/* User dropdown */}
            <div className="relative">
              <button
                onClick={() => setUserMenuOpen(!userMenuOpen)}
                aria-expanded={userMenuOpen}
                aria-haspopup="true"
                className="w-9 h-9 rounded-xl bg-gradient-to-br from-sw-accent to-violet-600 flex items-center justify-center text-white text-sm font-bold cursor-pointer ring-2 ring-sw-accent/20 ring-offset-1 transition-all [transition-duration:150ms] hover:ring-sw-accent/40 hover:shadow-sw-accent active:scale-95"
              >
                {user.name?.charAt(0)?.toUpperCase() || 'U'}
              </button>

              {userMenuOpen && (
                <>
                  <div className="fixed inset-0 z-30" onClick={() => setUserMenuOpen(false)} />
                  <div role="menu" className="absolute right-0 mt-2 w-48 rounded-xl border border-sw-border bg-sw-card shadow-lg z-40 py-1">
                    <div className="px-4 py-2 border-b border-sw-border">
                      <div className="text-sm font-medium text-sw-text truncate">{user.name}</div>
                      <div className="text-xs text-sw-dim truncate">{user.email}</div>
                    </div>
                    <Link
                      href="/settings"
                      role="menuitem"
                      className="flex items-center gap-2 px-4 py-2 text-sm text-sw-muted hover:text-sw-text hover:bg-sw-card-hover transition"
                    >
                      <Settings size={14} /> Profile &amp; Settings
                    </Link>
                    <button
                      onClick={() => {
                        // Clear authentication tokens before logout
                        (window as any).__clearAuthState?.();
                        router.post('/logout');
                      }}
                      role="menuitem"
                      className="flex items-center gap-2 w-full px-4 py-2 text-sm text-sw-muted hover:text-sw-danger hover:bg-sw-card-hover transition"
                    >
                      <LogOut size={14} /> Log Out
                    </button>
                  </div>
                </>
              )}
            </div>
          </div>
        </header>

        {/* Email connection banner */}
        {showEmailBanner && (
          <div className="shrink-0 border-b border-blue-200 bg-gradient-to-r from-blue-50 to-indigo-50">
            <div className="flex flex-wrap items-center gap-3 px-6 py-3">
              <Mail size={18} className="shrink-0 text-blue-600" />
              <p className="flex-1 min-w-[220px] text-sm text-blue-900">
                <span className="font-semibold">Get more from SpendifiAI</span> — link your email to automatically match online receipts with your transactions.
                <button
                  onClick={() => setEmailBannerExpanded(!emailBannerExpanded)}
                  className="ml-1.5 inline-flex items-center gap-0.5 text-blue-600 font-medium hover:text-blue-800 transition-colors"
                >
                  {emailBannerExpanded ? 'Show less' : 'Learn more'}
                  {emailBannerExpanded ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
                </button>
              </p>
              <Link
                href="/connect"
                className="shrink-0 rounded-lg bg-blue-600 px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-blue-700 transition-colors"
              >
                Link Email
              </Link>
              <button
                onClick={dismissEmailBanner}
                aria-label="Dismiss banner"
                className="shrink-0 text-blue-400 hover:text-blue-600 transition-colors"
              >
                <X size={16} />
              </button>
            </div>
            {emailBannerExpanded && (
              <div className="px-6 pb-3 pt-0 text-sm text-blue-800 leading-relaxed">
                <ul className="ml-6 list-disc space-y-1">
                  <li>Automatically parse order receipts from Amazon, Walmart, Target, and more</li>
                  <li>Match email receipts to bank transactions for accurate expense tracking</li>
                  <li>Identify tax-deductible purchases with line-item detail</li>
                  <li>Keep your transaction categories organized without manual work</li>
                </ul>
              </div>
            )}
          </div>
        )}

        {/* Page content */}
        <main id="main-content" role="main" className="flex-1 overflow-y-auto p-6">
          {children}
        </main>
      </div>

      {/* Impersonation bar (shown when accountant is viewing client data) */}
      <ImpersonationBar />
    </div>
    </>
  );
}
