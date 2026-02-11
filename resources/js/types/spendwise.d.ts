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
  amount: number;
  frequency: string;
  status: string;
  category: string | null;
  last_charge_date: string | null;
  next_expected_date: string | null;
  annual_cost: number;
  is_active: boolean;
}

export interface AIQuestion {
  id: number;
  transaction_id: number;
  question_text: string;
  question_type: string;
  status: string;
  options: string[] | null;
  user_answer: string | null;
  created_at: string;
  transaction?: Transaction;
}

export interface SavingsRecommendation {
  id: number;
  title: string;
  description: string;
  potential_savings: number;
  priority: string;
  category: string;
  status: string;
  action_steps: string[] | null;
  related_merchants: string[] | null;
}

export interface SavingsTarget {
  id: number;
  name: string;
  target_amount: number;
  current_amount: number;
  deadline: string | null;
  monthly_target: number;
  status: string;
  actions?: SavingsPlanAction[];
}

export interface SavingsPlanAction {
  id: number;
  title: string;
  description: string;
  estimated_savings: number;
  status: string;
  frequency: string | null;
}

export interface ExpenseCategory {
  id: number;
  name: string;
  slug: string;
  irs_category: string | null;
  tax_line: string | null;
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
  accounts_summary: { personal: number; business: number; mixed: number };
}

export interface TaxSummary {
  year: number;
  total_business_expenses: number;
  total_personal_expenses: number;
  total_tax_deductible: number;
  deductions_by_category: Array<{
    category: string;
    tax_line: string;
    total: number;
    count: number;
  }>;
}

export interface UserFinancialProfile {
  employment_type: string | null;
  filing_status: string | null;
  monthly_income: number | null;
  business_type: string | null;
  tax_year_start: string | null;
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
