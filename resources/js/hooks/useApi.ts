import { useState, useEffect, useCallback, useRef } from 'react';
import axios, { AxiosError, AxiosRequestConfig, Method } from 'axios';

interface UseApiOptions {
  immediate?: boolean;
}

interface UseApiReturn<T> {
  data: T | null;
  loading: boolean;
  error: string | null;
  refresh: () => void;
  mutate: (newData: T) => void;
}

export function useApi<T>(url: string, options?: UseApiOptions): UseApiReturn<T> {
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const mountedRef = useRef(true);
  const immediate = options?.immediate !== false;

  const fetchData = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await axios.get<T>(url);
      if (mountedRef.current) {
        setData(response.data);
      }
    } catch (err) {
      if (mountedRef.current) {
        const axiosError = err as AxiosError<{ message?: string }>;
        setError(axiosError.response?.data?.message || axiosError.message || 'An error occurred');
      }
    } finally {
      if (mountedRef.current) {
        setLoading(false);
      }
    }
  }, [url]);

  useEffect(() => {
    mountedRef.current = true;
    if (immediate) {
      fetchData();
    }
    return () => {
      mountedRef.current = false;
    };
  }, [fetchData, immediate]);

  const mutate = useCallback((newData: T) => {
    setData(newData);
  }, []);

  return { data, loading, error, refresh: fetchData, mutate };
}

interface UseApiPostReturn<T, D> {
  submit: (data?: D, config?: AxiosRequestConfig) => Promise<T | undefined>;
  loading: boolean;
  error: string | null;
  data: T | null;
}

export function useApiPost<T = unknown, D = unknown>(
  url: string,
  method: Method = 'POST'
): UseApiPostReturn<T, D> {
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = useCallback(
    async (payload?: D, config?: AxiosRequestConfig): Promise<T | undefined> => {
      setLoading(true);
      setError(null);
      try {
        const response = await axios.request<T>({
          url,
          method,
          data: payload,
          ...config,
        });
        setData(response.data);
        return response.data;
      } catch (err) {
        const axiosError = err as AxiosError<{ message?: string }>;
        const msg = axiosError.response?.data?.message || axiosError.message || 'An error occurred';
        setError(msg);
        return undefined;
      } finally {
        setLoading(false);
      }
    },
    [url, method]
  );

  return { submit, loading, error, data };
}
