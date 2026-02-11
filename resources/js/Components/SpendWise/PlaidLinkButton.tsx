import { useState, useCallback, useEffect } from 'react';
import { usePlaidLink, PlaidLinkOnSuccess } from 'react-plaid-link';
import { Building2, Loader2 } from 'lucide-react';
import axios from 'axios';

interface PlaidLinkButtonProps {
  onSuccess: () => void;
  onError?: (msg: string) => void;
  className?: string;
}

export default function PlaidLinkButton({ onSuccess, onError, className }: PlaidLinkButtonProps) {
  const [linkToken, setLinkToken] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [exchanging, setExchanging] = useState(false);

  useEffect(() => {
    async function fetchLinkToken() {
      setLoading(true);
      try {
        const res = await axios.post<{ link_token: string }>('/api/v1/plaid/link-token');
        setLinkToken(res.data.link_token);
      } catch (err) {
        onError?.('Failed to create link token');
      } finally {
        setLoading(false);
      }
    }
    fetchLinkToken();
  }, []);

  const handleSuccess = useCallback<PlaidLinkOnSuccess>(
    async (publicToken) => {
      setExchanging(true);
      try {
        await axios.post('/api/v1/plaid/exchange', { public_token: publicToken });
        onSuccess();
      } catch (err) {
        onError?.('Failed to exchange token');
      } finally {
        setExchanging(false);
      }
    },
    [onSuccess, onError]
  );

  const { open, ready } = usePlaidLink({
    token: linkToken,
    onSuccess: handleSuccess,
  });

  const isDisabled = !ready || loading || exchanging;

  return (
    <button
      onClick={() => open()}
      disabled={isDisabled}
      className={`inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-sw-accent text-sw-bg font-semibold text-sm hover:bg-sw-accent-hover transition disabled:opacity-50 disabled:cursor-not-allowed ${className || ''}`}
    >
      {loading || exchanging ? (
        <Loader2 size={16} className="animate-spin" />
      ) : (
        <Building2 size={16} />
      )}
      {loading ? 'Preparing...' : exchanging ? 'Connecting...' : 'Connect Your Bank'}
    </button>
  );
}
