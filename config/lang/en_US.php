<?php

declare(strict_types=1);

return [
    // Auth & Security
    'auth.login_title' => 'Sign In',
    'auth.register_title' => 'Create Account',
    'auth.forgot_password' => 'Forgot Password',
    'auth.reset_password' => 'Reset Password',
    'auth.invalid_credentials' => 'Invalid credentials.',
    'auth.account_inactive' => 'Account is inactive.',
    'auth.account_locked' => 'Account is temporarily locked. Please try again later.',
    'auth.password_reset_sent' => 'Password reset link sent to :email.',
    'auth.password_reset_success' => 'Password has been reset successfully.',
    'auth.welcome_user' => 'Welcome, :name!',
    'auth.logout_success' => 'You have been logged out successfully.',
    'auth.session_expired' => 'Your session has expired. Please sign in again.',

    // Two-Factor Authentication
    'two_factor.required' => 'Please enter your two-factor authentication code.',
    'two_factor.invalid_code' => 'The provided 2FA code is invalid.',
    'two_factor.backup_code_used' => 'A backup recovery code was used for authentication.',
    'two_factor.enabled' => 'Two-factor authentication enabled successfully.',
    'two_factor.disabled' => 'Two-factor authentication disabled successfully.',

    // Organizations
    'org.created' => 'Organization :name created successfully.',
    'org.switch_success' => 'Switched to organization :slug.',
    'org.member_invited' => 'Invitation sent to :email.',
    'org.member_removed' => 'Member :name was removed from the organization.',

    // Impersonation
    'impersonation.notice' => 'You are currently impersonating user :user.',
    'impersonation.stopped' => 'Impersonation session ended. Returned to :admin.',

    // Navigation
    'nav.dashboard' => 'Dashboard',
    'nav.services' => 'Services',
    'nav.domains' => 'Domains',
    'nav.billing' => 'Billing',
    'nav.invoices' => 'Invoices',
    'nav.support' => 'Support',
    'nav.tickets' => 'Tickets',
    'nav.announcements' => 'Announcements',
    'nav.settings' => 'Settings',
    'nav.users' => 'Users',
    'nav.system_health' => 'System Health',

    // Common Actions & Controls
    'common.save' => 'Save Changes',
    'common.cancel' => 'Cancel',
    'common.delete' => 'Delete',
    'common.edit' => 'Edit',
    'common.create' => 'Create New',
    'common.confirm' => 'Confirm',
    'common.back' => 'Back',
    'common.next' => 'Next',
    'common.search' => 'Search...',
    'common.filter' => 'Filter',
    'common.export' => 'Export',
    'common.close' => 'Close',
    'common.actions' => 'Actions',
    'common.status' => 'Status',
    'common.date' => 'Date',
    'common.details' => 'Details',
    'common.loading' => 'Loading...',
    'common.no_records' => 'No records found.',
    'common.success' => 'Operation completed successfully.',
    'common.error' => 'An unexpected error occurred.',

    // Billing & Invoices
    'billing.invoices' => 'Invoices',
    'billing.invoice_number' => 'Invoice #:number',
    'billing.total' => 'Total',
    'billing.subtotal' => 'Subtotal',
    'billing.tax' => 'Tax',
    'billing.due_date' => 'Due Date',
    'billing.issue_date' => 'Issue Date',
    'billing.amount_due' => 'Amount Due',
    'billing.pay_now' => 'Pay Now',
    'billing.download_pdf' => 'Download PDF',
    'billing.credit_balance' => 'Available Credit: :amount',
    'billing.credit_applied' => 'Credit of :amount applied to invoice :number.',
    'billing.payment_successful' => 'Payment for invoice :number completed successfully.',
    'billing.payment_failed' => 'Payment failed: :reason',

    // Services
    'services.my_services' => 'My Services',
    'services.service_name' => 'Service',
    'services.domain' => 'Domain',
    'services.billing_cycle' => 'Billing Cycle',
    'services.next_due' => 'Next Due Date',
    'services.manage' => 'Manage Service',
    'services.active_services_count' => 'You have :count active services.',
    'services.renew' => 'Renew Service',

    // Domains
    'domains.my_domains' => 'My Domains',
    'domains.domain_name' => 'Domain Name',
    'domains.registration_date' => 'Registered On',
    'domains.expiry_date' => 'Expires On',
    'domains.auto_renew' => 'Auto Renewal',
    'domains.nameservers' => 'Nameservers',
    'domains.manage_dns' => 'Manage DNS',

    // Support Tickets
    'tickets.my_tickets' => 'Support Tickets',
    'tickets.open_ticket' => 'Open New Ticket',
    'tickets.subject' => 'Subject',
    'tickets.department' => 'Department',
    'tickets.priority' => 'Priority',
    'tickets.last_reply' => 'Last Reply',
    'tickets.ticket_number' => 'Ticket #:number',
    'tickets.created_success' => 'Ticket #:number created successfully.',
    'tickets.reply_submitted' => 'Reply posted to ticket #:number.',
    'tickets.closed' => 'Ticket #:number has been marked as closed.',

    // Lifecycle Statuses
    'status.active' => 'Active',
    'status.pending' => 'Pending',
    'status.suspended' => 'Suspended',
    'status.terminated' => 'Terminated',
    'status.cancelled' => 'Cancelled',
    'status.paid' => 'Paid',
    'status.unpaid' => 'Unpaid',
    'status.overdue' => 'Overdue',
    'status.refunded' => 'Refunded',
    'status.open' => 'Open',
    'status.answered' => 'Answered',
    'status.customer_reply' => 'Customer Reply',
    'status.closed' => 'Closed',

    // Validation Messages
    'validation.required' => 'The :field field is required.',
    'validation.email' => 'The :field field must be a valid email address.',
    'validation.min_length' => 'The :field must be at least :min characters.',
    'validation.max_length' => 'The :field may not be greater than :max characters.',
    'validation.numeric' => 'The :field must be a numeric value.',
];
