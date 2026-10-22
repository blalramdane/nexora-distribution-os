export type PrintCell = string | number | boolean | null | undefined;
export type PrintTable = { title: string; headers: string[]; rows: PrintCell[][] };
export type PrintReport = { title: string; subtitle: string; summaryRows?: [string, PrintCell][]; tables?: PrintTable[]; notes?: string[] };
export function buildReportPrintHtml(report: PrintReport): string;

