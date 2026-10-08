"use client";

import { useEffect } from "react";
import { api } from "@/lib/api";
import { flushOfflineQueue } from "@/lib/sync";

export default function SyncBootstrap() {
  useEffect(() => {
    const flush = () => {
      if (navigator.onLine) void flushOfflineQueue(api);
    };
    flush();
    window.addEventListener("online", flush);
    const interval = window.setInterval(flush, 30000);
    return () => {
      window.removeEventListener("online", flush);
      window.clearInterval(interval);
    };
  }, []);
  return null;
}
