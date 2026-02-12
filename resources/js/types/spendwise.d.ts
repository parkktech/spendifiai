export type ActionResponseType = 'cancelled' | 'reduced' | 'kept';

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
  is_subscription: boolean;
  category: string;
  account?: BankAccount;
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
}

export interface BankConnection {
  id: number;
  institution_name: string;
  status: string;
  last_synced_at: string | null;
  error_code: string | null;
  error_message: string | null;
  accounts: BankAccount[];
}

export interface Subscription {
  id: number;
  merchant_name: string;
  merchant_normalized: string | null;
  amount: number;
  frequency: string;
  status: string;
  category: string | null;
  is_essential: boolean | null;
  last_charge_date: string | null;
  next_expected_date: string | null;
  last_used_at: string | null;
  annual_cost: number;
  charge_history: unknown[] | null;
  response_type: ActionResponseType | null;
  previous_amount: number | null;
  response_reason: string | null;
  has_alternatives: boolean;
  responded_at: string | null;
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
  projected_savings: ProjectedSavings;
  savings_history: SavingsHistoryEntry[];
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
}

export interface UserFinancialProfile {
  employment_type: string | null;
  tax_filing_status: string | null;
  monthly_income: number | null;
  business_type: string | null;
  has_home_office: boolean | null;
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

export interface StatementProcessingStatus {
  upload_id: number;
  status: 'uploading' | 'parsing' | 'extracting' | 'analyzing' | 'complete' | 'error';
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
  current_dti: number;
  down_payment: number;
  interest_rate: number;
  max_monthly_payment: number;
  max_loan_amount: number;
  max_home_price: number;
  estimated_monthly_mortgage: number;
  loan_term_years: number;
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
