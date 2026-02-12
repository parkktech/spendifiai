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
  spending_trend: Array<{ month: string; total: number }>;
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
  ai_stats: {
    auto_categorized: number;
    pending_review: number;
    questions_generated: number;
  };
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
