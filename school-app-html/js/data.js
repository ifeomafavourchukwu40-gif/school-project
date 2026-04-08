import { apiGet, apiPost } from "../assets/js/storage.js";

export async function getStudents() {
  return await apiGet('../api/students.php?action=list');
}

export async function addStudent(student) {
  return await apiPost('../api/students.php?action=add', student);
}

export async function findStudentsByName(q) {
  const term = q.toLowerCase().trim();
  if (!term) return [];
  return await apiGet('../api/students.php?action=list&q=' + encodeURIComponent(term));
}

export async function getStudentById(id) {
  return await apiGet('../api/students.php?action=get&id=' + encodeURIComponent(id));
}

export async function getInvoices() {
  return await apiGet('../api/invoices.php?action=list');
}

export async function openOrCreateInvoice({ studentId, term, year }) {
  return await apiPost('../api/invoices.php?action=open_or_create', { studentId, term, year });
}

export async function getInvoiceById(id) {
  return await apiGet('../api/invoices.php?action=get&id=' + encodeURIComponent(id));
}

export async function addInvoiceItem(invoiceId, { name, amount }) {
  return await apiPost('../api/invoices.php?action=add_item', { invoiceId, name, amount });
}

export async function removeInvoiceItem(invoiceId, itemId) {
  return await apiPost('../api/invoices.php?action=remove_item', { itemId });
}

export function invoiceTotal(inv) {
  return (inv.items || []).reduce((sum, it) => sum + (Number(it.amount) || 0), 0);
}

export async function setInvoiceStatus(invoiceId, status) {
  return await apiPost('../api/invoices.php?action=update_status', { id: invoiceId, status });
}