export type ActionResponseType = 'cancelled' | 'reduced' | 'kept';

export interface TransactionOrderItem {
  id: number;
  product_name: string;
  product_description: string | null;
  quantity: number;
  total_price: number;
  ai_category: string | null;
  user_category: string | null;
  expense_type: 'personal' | 'business';
  tax_deductible: boolean;
  tax_deductible_confidence: number | null;
}

export interface Transaction {
  id: number;
  merchant_name: string;
  amount: number;
  date: string;
  description: string | null;
  ai_category: string | null;
  user_category: string | null;
  ai_confidence: number | null;
  review_status: string;
  expense_type: string;
  account_purpose: string;
  tax_deductible: boolean;
  donation_note: string | null;
  is_subscription: boolean;
  is_reconciled: boolean;
  matched_order_id: number | null;
  category: string;
  account?: BankAccount;
  order_items?: TransactionOrderItem[];
}

export interface BankAccount {
  id: number;
  name: string;
  official_name: string | null;
  type: string;
  subtype: string | null;
  mask: string | null;
  current_balance: number | null;
  available_balance: number | null;
  purpose: string;
  institution_name: string | null;
  connection?: {
    id: number;
    institution_name: string;
    status: string;
    is_plaid: boolean;
    last_synced_at: string | null;
    statements_supported?: boolean | null;
    statements_refresh_status?: string | null;
  };
}

export interface BankConnection {
  id: number;
  institution_name: string;
  status: string;
  is_plaid?: boolean;
  last_synced_at: string | null;
  error_code: string | null;
  error_message: string | null;
  statements_supported?: boolean | null;
  statements_refresh_status?: string | null;
  accounts: BankAccount[];
}

export interface PlaidStatementRecord {
  id: number;
  month: number;
  year: number;
  status: string;
  total_extracted: number;
  duplicates_found: number;
  transactions_imported: number;
  date_range_from: string | null;
  date_range_to: string | null;
  created_at: string | null;
}

export interface TaxDeduction {
  id: number;
  slug: string;
  name: string;
  description: string;
  category: string;
  subcategory: string | null;
  max_amount: number | null;
  max_amount_mfj: number | null;
  is_credit: boolean;
  is_refundable: boolean;
  irs_form: string | null;
  irs_line: string | null;
  detection_method: string;
  question_text: string | null;
  question_options: Record<string, string>[] | null;
  help_text: string | null;
  irs_url: string | null;
}

export interface UserTaxDeduction {
  id: number;
  tax_deduction_id: number;
  tax_year: number;
  status: string;
  estimated_amount: number | null;
  actual_amount: number | null;
  answer: Record<string, unknown> | null;
  detected_from: string | null;
  detection_confidence: number | null;
  notes: string | null;
  deduction?: TaxDeduction;
}

export interface Subscription {
  id: number;
  merchant_name: string;
  merchant_normalized: string | null;
  description: string | null;
  user_notes: string | null;
  amount: number;
  frequency: string;
  status: string;
  category: string | null;
  is_essential: boolean | null;
  last_charge_date: string | null;
  next_expected_date: string | null;
  last_used_at: string | null;
  annual_cost: number;
  months_active: number | null;
  first_charge_date: string | null;
  charge_history: unknown[] | null;
  response_type: ActionResponseType | null;
  previous_amount: number | null;
  response_reason: string | null;
  has_alternatives: boolean;
  responded_at: string | null;
  cancellation_url: string | null;
  cancellation_phone: string | null;
  cancellation_instructions: string | null;
  cancellation_difficulty: 'easy' | 'medium' | 'hard' | null;
}

export interface SubscriptionsResponse {
  subscriptions: Subscription[];
  total_monthly: number;
  total_annual: number;
  unused_monthly: number;
  unused_count: number;
}

export interface AIQuestion {
  id: number;
  question: string;
  question_type: string;
  status: string;
  email_search_status: 'searching' | 'found' | 'no_results' | null;
  options: string[] | null;
  ai_confidence: number | null;
  user_answer: string | null;
  answered_at: string | null;
  created_at: string;
  transaction?: Transaction;
}

export interface SavingsRecommendation {
  id: number;
  title: string;
  description: string;
  monthly_savings: number;
  annual_savings: number;
  difficulty: string;
  category: string;
  impact: string | null;
  status: string;
  action_steps: string[] | null;
  related_merchants: string[] | null;
  created_at: string;
  response_type: ActionResponseType | null;
  response_data: { new_amount?: number; reason?: string; responded_at?: string } | null;
  actual_monthly_savings: number | null;
  has_alternatives: boolean;
}

export interface RecommendationsResponse {
  recommendations: SavingsRecommendation[];
  total_monthly: number;
  total_annual: number;
  easy_wins_monthly: number;
}

export interface SavingsTarget {
  id: number;
  monthly_target: number;
  motivation: string | null;
  target_start_date: string | null;
  target_end_date: string | null;
  goal_total: number | null;
  is_active: boolean;
  created_at: string;
  actions?: SavingsPlanAction[];
  progress?: unknown;
}

export interface SavingsTargetResponse {
  has_target: boolean;
  message?: string;
  target?: SavingsTarget;
  current_month?: {
    cumulative_saved: number;
    [key: string]: unknown;
  };
  progress_history?: unknown[];
  time_to_goal?: {
    months_remaining: number;
    projected_date: string;
    on_pace: boolean;
    pct_complete: number;
  } | null;
  plan?: {
    accepted_actions: SavingsPlanAction[];
    suggested_actions: SavingsPlanAction[];
    accepted_total_savings: number;
    suggested_total_savings: number;
    full_plan_savings: number;
  };
}

export interface SavingsPlanAction {
  id: number;
  title: string;
  description: string;
  monthly_savings: number;
  category: string | null;
  recommended_spending: number | null;
  status: string;
  priority: number | null;
  accepted_at: string | null;
  rejected_at: string | null;
  rejection_reason: string | null;
}

export interface ExpenseCategory {
  id: number;
  name: string;
  slug: string;
}

export interface DashboardData {
  view_mode: 'all' | 'personal' | 'business';
  summary: {
    this_month_spending: number;
    this_month_income: number;
    last_month_spending: number;
    last_month_income: number;
    net_this_month: number;
    month_over_month: number;
    potential_savings: number;
    tax_deductible_ytd: number;
    needs_review: number;
    unused_subscriptions: number;
    pending_questions: number;
  };
  categories: Array<{ category: string; total: number; count: number }>;
  questions: AIQuestion[];
  recent: Transaction[];
  spending_trend: Array<{ month: string; expenses: number; income: number }>;
  sync_status: { status: string; last_synced_at: string; institution_name: string } | null;
  accounts_summary: Record<string, number>;
  savings_recommendations: SavingsRecommendation[];
  savings_target: {
    monthly_target: number;
    motivation: string | null;
    goal_total: number | null;
    current_month: { cumulative_saved: number; [key: string]: unknown } | null;
  } | null;
  unused_subscription_details: Array<{
    id: number;
    merchant_name: string;
    merchant_normalized: string | null;
    amount: number;
    last_charge_date: string | null;
    last_used_at: string | null;
    annual_cost: number;
  }>;
  savings_opportunities: Array<{
    category: string;
    total_3mo: number;
    monthly_avg: number;
    transaction_count: number;
    avg_transaction: number;
  }>;
  free_to_spend: number;
  applied_this_month: Array<{
    id: number;
    title: string;
    monthly_savings: number;
    category: string;
    applied_at: string;
  }>;
  applied_savings_total: number;
  ai_stats: {
    auto_categorized: number;
    pending_review: number;
    questions_generated: number;
  };
  recurring_bills: RecurringBill[];
  total_monthly_bills: number;
  budget_waterfall: BudgetWaterfall;
  home_affordability: HomeAffordability;
  cost_of_living: CostOfLivingData;
  income_sources: IncomeBreakdown;
  primary_vs_extra: PrimaryVsExtra;
  projected_savings: ProjectedSavings;
  savings_history: SavingsHistoryEntry[];
  top_stores: TopStore[];
  top_stores_total: number;
  charitable_giving: CharitableGiving;
  period: PeriodMeta;
}

export interface CharitableGiving {
  period_total: number;
  ytd_total: number;
  transaction_count: number;
  ytd_count: number;
  estimated_tax_savings: number;
  tax_rate_used: number;
  top_recipients: Array<{ recipient: string; total: number; count: number; note: string | null }>;
  recent_donations: Array<{ id: number; merchant: string; amount: number; date: string; note: string | null; tax_deductible: boolean }>;
  recommended_charities: Array<{
    name: string;
    description: string | null;
    donate_url: string | null;
    website_url: string | null;
    category: string;
    ein: string | null;
  }>;
}

export interface TaxLineItem {
  date: string;
  merchant: string;
  description: string;
  amount: number;
  category: string;
  source: 'bank' | 'email';
  order_number?: string;
  donation_note?: string | null;
}

export interface NormalizedTaxLine {
  line: string;
  label: string;
  schedule: 'C' | 'A';
  total: number;
  categories: Array<{ name: string; amount: number; items: number }>;
}

export interface TaxSummary {
  year: number;
  total_deductible: number;
  estimated_tax_savings: number;
  effective_rate_used: number;
  transaction_categories: Array<{
    category: string;
    total: number;
    item_count: number;
  }>;
  order_item_categories: Array<{
    category: string;
    total: number;
    item_count: number;
  }>;
  transaction_details: TaxLineItem[];
  order_item_details: TaxLineItem[];
  schedule_c_map: Record<string, { line: string; label: string; schedule?: string }>;
  normalized_lines: NormalizedTaxLine[];
  schedule_c_total: number;
  schedule_a_total: number;
}

export interface UserFinancialProfile {
  employment_type: string | null;
  tax_filing_status: string | null;
  monthly_income: number | null;
  business_type: string | null;
  has_home_office: boolean | null;
  housing_status: string | null;
}

export interface UserFinancialProfileResponse {
  message?: string;
  profile: UserFinancialProfile | null;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
  links: {
    first: string;
    last: string;
    prev: string | null;
    next: string | null;
  };
}

// --- Statement Upload Types ---

export interface ParsedTransaction {
  row_index: number;
  date: string;
  description: string;
  amount: number;
  merchant_name: string;
  is_income: boolean;
  is_duplicate: boolean;
  confidence: number;
  original_text?: string;
  source_upload_id?: number;
  source_file_name?: string;
  duplicate_reason?: 'db' | 'cross_file';
}

export interface StatementUploadResult {
  upload_id: number;
  file_name: string;
  total_extracted: number;
  duplicates_found: number;
  transactions: ParsedTransaction[];
  date_range: { from: string; to: string };
  processing_notes: string[];
}

export interface StatementUploadHistory {
  id: number;
  file_name: string;
  bank_name: string;
  account_type: string;
  transactions_imported: number;
  duplicates_skipped: number;
  uploaded_at: string;
  date_range: { from: string; to: string };
}

export interface PendingUpload {
  id: number;
  file_name: string;
  status: 'queued' | 'parsing' | 'extracting' | 'analyzing';
  bank_name: string;
  account_type: string;
  bank_account_id: number;
  created_at: string;
}

export interface StatementGap {
  gap_key: string;
  account_id: number;
  account_name: string;
  month: string;
  month_label: string;
  date_range: { from: string; to: string } | null;
  transaction_count: number;
  average_count: number;
  severity: 'critical' | 'warning';
  reason: string;
  has_statement: boolean;
  gap_type: 'full_month' | 'partial_month' | 'low_activity';
}

export interface StatementOverlap {
  account_id: number;
  account_name: string;
  overlap_range: { from: string; to: string };
  statements: Array<{ id: number; file_name: string }>;
  severity: 'info';
}

export interface AccountCoverage {
  account_id: number;
  account_name: string;
  institution_name: string | null;
  date_range: { from: string; to: string };
  total_months: number;
  average_monthly_transactions: number;
  gap_count: number;
  months: Array<{
    month: string;
    transaction_count: number;
    has_statement: boolean;
    coverage_ranges: Array<{ from: string; to: string }>;
    first_date: string | null;
    last_date: string | null;
  }>;
}

export interface StatementGapResponse {
  gaps: StatementGap[];
  overlaps: StatementOverlap[];
  coverage: {
    accounts: AccountCoverage[];
  };
}

export interface StatementProcessingStatus {
  upload_id: number;
  status: 'uploading' | 'queued' | 'parsing' | 'extracting' | 'analyzing' | 'complete' | 'error';
  progress: number;
  current_page?: number;
  total_pages?: number;
  message: string;
  transactions_found?: number;
}

export interface StatementImportResult {
  imported: number;
  skipped: number;
  errors: number;
  message: string;
}

export interface BatchStatusUpload {
  upload_id: number;
  status: string;
  file_name: string | null;
  error_message?: string | null;
  total_extracted?: number;
  duplicates_found?: number;
  date_range?: { from: string; to: string };
}

export interface BatchStatusResponse {
  uploads: BatchStatusUpload[];
  summary: {
    total: number;
    completed: number;
    failed: number;
    processing: number;
    all_done: boolean;
    total_extracted: number;
    total_duplicates: number;
  };
}

export interface BatchTransactionsResponse {
  transactions: ParsedTransaction[];
  total_extracted: number;
  duplicates_found: number;
  db_duplicates: number;
  cross_file_duplicates: number;
  date_range: { from: string; to: string };
  processing_notes: string[];
  files_included: number;
}

// --- Recurring Bills & Budget Analysis ---

export interface RecurringBill {
  id: number;
  merchant_name: string;
  merchant_normalized: string | null;
  amount: number;
  frequency: string;
  status: string;
  is_essential: boolean | null;
  last_charge_date: string | null;
  next_expected_date: string | null;
  annual_cost: number;
  response_type?: string | null;
  responded_at?: string | null;
}

export interface BudgetWaterfall {
  monthly_income: number;
  essential_bills: number;
  non_essential_subscriptions: number;
  discretionary_spending: number;
  total_spending: number;
  monthly_surplus: number;
  can_save: boolean;
  savings_rate: number;
}

export interface HomeAffordability {
  monthly_income: number;
  monthly_debt: number;
  non_housing_debt: number;
  current_dti: number;
  down_payment: number;
  interest_rate: number;
  max_monthly_payment: number;
  max_loan_amount: number;
  max_home_price: number;
  estimated_monthly_mortgage: number;
  loan_term_years: number;
  income_source: 'profile' | 'estimated';
}

// --- Cost of Living Types ---

export interface CostOfLivingMerchant {
  name: string;
  monthly_avg: number;
}

export interface CostOfLivingItem {
  category: string;
  monthly_avg: number;
  total_3mo: number;
  transaction_count: number;
  top_merchants: CostOfLivingMerchant[];
}

export interface CostOfLivingData {
  items: CostOfLivingItem[];
  total_essential_monthly: number;
  discretionary_monthly: number;
  monthly_income: number;
  reliable_monthly_income: number;
  months_analyzed: number;
}

// --- Income Detection Types ---

export interface IncomeSource {
  type: 'employment' | 'contractor' | 'interest' | 'transfer' | 'other';
  label: string;
  merchant_name: string;
  avg_amount: number;
  monthly_equivalent: number;
  frequency: string | null;
  is_regular: boolean;
  occurrences: number;
  classification: 'primary' | 'extra';
}

export interface IncomeBreakdown {
  sources: IncomeSource[];
  reliable_monthly: number;
  total_monthly_avg: number;
  primary_monthly: number;
  extra_monthly: number;
  months_analyzed: number;
}

export interface PrimaryVsExtra {
  primary_income: number;
  extra_income: number;
  primary_expenses: number;
  extra_expenses: number;
  primary_surplus: number;
  can_live_on_primary: boolean;
  coverage_pct: number;
}

// --- Savings Action Response Types ---

export interface AlternativeSuggestion {
  name: string;
  provider: string;
  cost: number;
  savings_vs_current: number;
  description: string;
  switch_effort: 'easy' | 'medium' | 'hard';
  trade_offs: string;
}

export interface ProjectedSavings {
  projected_monthly_savings: number;
  projected_annual_savings: number;
  breakdown: {
    recommendations: number;
    cancelled_subscriptions: number;
    reduced_subscriptions: number;
  };
  verification: {
    total_actions: number;
    verified: number;
    pending_verification: number;
    verified_savings: number;
  };
}

export interface SavingsHistoryEntry {
  month: string;
  total_savings: number;
  actions_count: number;
  verified_savings: number;
  subscription_savings: number;
  recommendation_savings: number;
}

// --- Timeline / Period Types ---

export type TimelinePeriod =
  | 'this_month'
  | 'last_month'
  | 'last_3_months'
  | 'last_6_months'
  | 'last_year'
  | 'ytd'
  | 'custom';

export interface PeriodMeta {
  start: string;
  end: string;
  months: number;
  avg_mode: 'total' | 'monthly_avg';
  is_custom: boolean;
}

// --- Top Stores Types ---

export interface TopStore {
  store_name: string;
  total_spent: number;
  transaction_count: number;
  avg_per_visit: number;
  pct_of_total: number;
  first_visit: string;
  last_visit: string;
  has_order_items: boolean;
  tax_deductible_total: number;
  tax_deductible_count: number;
}

export interface StoreMonthlyTrend {
  month: string;
  month_start: string;
  total: number;
  count: number;
}

export interface StoreTransaction {
  id: number;
  month: string;
  date: string;
  merchant_name: string;
  amount: number;
  description: string | null;
  category: string;
  expense_type: string;
  tax_deductible: boolean;
  is_reconciled: boolean;
  order_items: StoreOrderItem[];
}

export interface StoreOrderItem {
  id: number;
  product_name: string;
  product_description: string | null;
  quantity: number;
  total_price: number;
  ai_category: string | null;
  user_category: string | null;
  expense_type: 'personal' | 'business';
  tax_deductible: boolean;
  tax_deductible_confidence: number | null;
  order: {
    id: number;
    order_date: string;
    order_number: string | null;
    total: number;
  };
}

export interface StoreDetail {
  store_name: string;
  monthly_trend: StoreMonthlyTrend[];
  transactions: Record<string, StoreTransaction[]>;
  order_items: StoreOrderItem[];
}

// --- Admin / CancellationProvider Types ---

export interface CancellationProvider {
  id: number;
  company_name: string;
  slug: string;
  aliases: string[];
  cancellation_url: string | null;
  cancellation_phone: string | null;
  cancellation_instructions: string | null;
  difficulty: 'easy' | 'medium' | 'hard';
  category: string | null;
  is_essential: boolean;
  is_verified: boolean;
  created_at: string;
  updated_at: string;
}

export interface AdminStats {
  total_providers: number;
  verified_providers: number;
  unverified_providers: number;
  with_cancellation_url: number;
  categories: Array<{ category: string; count: number }>;
  recently_added: CancellationProvider[];
}

export interface AdminProvidersResponse {
  providers: CancellationProvider[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface CharitableOrganization {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  website_url: string | null;
  donate_url: string | null;
  category: string | null;
  ein: string | null;
  is_featured: boolean;
  is_active: boolean;
  sort_order: number;
  created_at: string;
  updated_at: string;
}

export interface AdminCharitiesResponse {
  charities: CharitableOrganization[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface AdminCharityStats {
  total_charities: number;
  active_charities: number;
  featured_charities: number;
  with_donate_url: number;
  categories: Array<{ category: string; count: number }>;
  recently_added: CharitableOrganization[];
}

// --- Cookie Consent Types ---

export interface ConsentPreferences {
  analytics: boolean;
  marketing: boolean;
  version: string;
  region?: string;
  updated_at?: string;
}

export interface ConsentConfig {
  region: 'eu' | 'california' | 'other';
  region_label: string;
  requires_opt_in: boolean;
  requires_opt_out_notice: boolean;
  consent_version: string;
  has_consent: boolean;
  current_preferences: { analytics: boolean; marketing: boolean } | null;
}

export interface ConsentAuditEntry {
  id: number;
  action: 'grant' | 'revoke' | 'update' | 'admin_override';
  analytics: boolean;
  marketing: boolean;
  region: string;
  version: string;
  admin_user_id: number | null;
  created_at: string;
}

export interface AdminConsentStats {
  total_users: number;
  users_with_consent: number;
  analytics_enabled: number;
  marketing_enabled: number;
  region_breakdown: Record<string, number>;
}

// --- Accountant Types ---

export interface AccountantClient {
    id: number;
    client: { id: number; name: string; email: string; company_name?: string };
    status: 'pending' | 'active' | 'revoked';
    invited_by: 'client' | 'accountant';
    has_bank: boolean;
    transaction_range?: { start: string; end: string };
    last_sync?: string;
    created_at: string;
}

export interface AccountantSearchResult {
    id: number;
    name: string;
    email: string;
    company_name?: string;
}

export interface MyAccountant {
    id: number;
    accountant: { id: number; name: string; email: string; company_name?: string };
    status: 'pending' | 'active' | 'revoked';
    invited_by: 'client' | 'accountant';
    created_at: string;
}

export interface AccountantInvite {
    id: number;
    accountant: { id: number; name: string; email: string; company_name?: string };
    client: { id: number; name: string; email: string };
    invited_by: 'client' | 'accountant';
    can_respond: boolean;
    created_at: string;
}

export interface AdminConsentUser {
  id: number;
  name: string;
  email: string;
  created_at: string;
  consent: {
    analytics: boolean;
    marketing: boolean;
    region: string;
    version: string;
    last_updated: string;
    action: string;
  } | null;
}
