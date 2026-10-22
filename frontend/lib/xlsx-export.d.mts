export type XlsxCell = string | number | boolean | null | undefined;
export type XlsxSheet = { name: string; rows: XlsxCell[][] };
export function createXlsxWorkbook(sheets: XlsxSheet[]): Uint8Array;

