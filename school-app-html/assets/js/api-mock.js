import { db, getSession, setSession } from './storage.js';

// Helper to simulate network delay
const delay = ms => new Promise(res => setTimeout(res, ms));

// Mock Database wrapper around localStorage
const Database = {
  get users() { return db.read('db_users', []); },
  set users(val) { db.write('db_users', val); },
  get invoices() { return db.read('db_invoices', []); },
  set invoices(val) { db.write('db_invoices', val); },
  get invoice_items() { return db.read('db_invoice_items', []); },
  set invoice_items(val) { db.write('db_invoice_items', val); },
  get activities() { return db.read('db_activities', []); },
  set activities(val) { db.write('db_activities', val); },
  get fees() {
    const defaults = [];
    ['First Term', 'Second Term', 'Third Term'].forEach(term => {
      ['JS 1', 'JS 2', 'JS 3'].forEach(cl => {
        defaults.push({ id: uuid(), term, year: '2025/2026', class_name: cl, amount: 10000 + (cl === 'JS 2' ? 2000 : cl === 'JS 3' ? 5000 : 0), description: 'Tuition Fee' });
      });
      ['SS 1', 'SS 2', 'SS 3'].forEach(cl => {
        defaults.push({ id: uuid(), term, year: '2025/2026', class_name: cl, amount: 20000 + (cl === 'SS 2' ? 2000 : cl === 'SS 3' ? 5000 : 0), description: 'Tuition Fee' });
      });
    });
    return db.read('db_fees', defaults);
  },
  set fees(val) { db.write('db_fees', val); },
  get settings() { return db.read('db_settings', { name: 'My School Name', address: 'School Address Here', current_term: 'First Term', current_year: '2025/2026' }); },
  set settings(val) { db.write('db_settings', val); }
};

// Generate UUID without relying on crypto (works on HTTP too)
function uuid() {
  return typeof crypto !== 'undefined' && crypto.randomUUID
    ? crypto.randomUUID()
    : Math.random().toString(36).substring(2) + Date.now().toString(36);
}

// Utility to parse URL safely (handles both relative and absolute paths)
function getUrlParams(url) {
  try {
    return new URL(url, 'http://localhost');
  } catch (e) {
    return new URL(url, window.location.href);
  }
}

export async function mockApiGet(url) {
  await delay(200); // simulate realistic network condition
  const session = getSession();
  const u = getUrlParams(url);
  const action = u.searchParams.get('action');

  if (url.includes('auth.php')) {
    if (action === 'me') {
      if (!session?.userId) return { user: null };
      const user = Database.users.find(u => u.id === session.userId);
      return { user: user || null };
    }
    if (action === 'session') {
      return { session: session || null };
    }
  }

  if (url.includes('settings.php')) {
    if (action === 'school') return Database.settings;
    if (action === 'activities') return Database.activities.sort((a, b) => new Date(b.date) - new Date(a.date));
  }

  if (url.includes('fees.php')) {
    if (!session?.userId) throw new Error("Not authenticated");

    if (action === 'my_fees') {
      const user = Database.users.find(u => u.id === session.userId);
      if (!user) throw new Error("User not found");
      const userClass = user.classLevel || 'JS 1';
      const settings = Database.settings;
      const term = u.searchParams.get('term') || settings.current_term || 'First Term';
      const year = u.searchParams.get('year') || settings.current_year || '2025/2026';

      const inv = Database.invoices.find(i => i.studentId === session.userId && i.term === term && i.year === year && i.status === 'UNPAID');
      let fees = [];
      if (inv) {
          const iItems = Database.invoice_items.filter(it => it.invoiceId === inv.id);
          fees = iItems.map(f => ({ description: f.name, amount: f.amount }));
      } else {
          fees = Database.fees.filter(f => f.term === term && f.class_name === userClass && (!f.year || f.year === year));
      }

      return { term, year, classLevel: userClass, fees, hasInvoice: !!inv };
    }

    if (action === 'list_all') {
      if (session?.role !== 'admin') throw new Error("Forbidden");
      const year = u.searchParams.get('year');
      const term = u.searchParams.get('term');
      let fData = Database.fees;
      if (year) fData = fData.filter(f => String(f.year) === String(year));
      if (term) fData = fData.filter(f => String(f.term) === String(term));
      return fData;
    }
  }

  if (url.includes('students.php')) {
    if (session?.role !== 'admin') throw new Error("Forbidden: Admins only");

    if (action === 'list') {
      const q = u.searchParams.get('q');
      let students = Database.users.filter(u => u.role === 'student');
      if (q) students = students.filter(s => s.fullName.toLowerCase().includes(q.toLowerCase()));
      return students;
    }

    if (action === 'get') {
      const id = u.searchParams.get('id');
      return Database.users.find(u => u.id === id && u.role === 'student') || null;
    }
  }

  if (url.includes('invoices.php')) {
    if (session?.role !== 'admin') throw new Error("Forbidden: Admins only");

    if (action === 'list') {
      return Database.invoices.sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt));
    }

    if (action === 'get') {
      const id = u.searchParams.get('id');
      const inv = Database.invoices.find(i => i.id === id);
      if (inv) {
        inv.items = Database.invoice_items.filter(item => item.invoiceId === id);
      }
      return inv || null;
    }
  }

  throw new Error(`Mock GET not implemented: ${url}`);
}

export async function mockApiPost(url, body) {
  await delay(200);
  const session = getSession();
  const u = getUrlParams(url);
  const action = u.searchParams.get('action');

  if (url.includes('auth.php')) {
    if (action === 'signup') {
      const { firstName, middleName, lastName, email, password, role } = body;
      const fullName = [firstName, middleName, lastName].filter(Boolean).join(" ");
      if (!email || password.length < 6) throw new Error("Invalid email or password too short");
      const users = Database.users;
      if (users.find(u => u.email.toLowerCase() === email.toLowerCase())) {
        throw new Error("Email already exists");
      }
      const finalRole = (role === 'admin' || role === 'student') ? role : 'student';
      const mockCode = String(Math.floor(100000 + Math.random() * 900000));
      const newUser = {
        id: uuid(),
        fullName,
        firstName,
        middleName,
        lastName,
        email: email.toLowerCase(),
        password,
        phone: '',
        address: '',
        avatarDataUrl: '',
        role: finalRole,
        isVerified: 0,
        verificationCode: mockCode,
        createdAt: new Date().toISOString()
      };
      Database.users = [...users, newUser];
      return { success: true, mockCode };
    }

    if (action === 'verify') {
      const { email, code } = body;
      const users = Database.users;
      const idx = users.findIndex(u => u.email.toLowerCase() === email.toLowerCase() && u.verificationCode === code && u.isVerified === 0);
      if (idx === -1) throw new Error("Invalid verification code or already verified");

      users[idx].isVerified = 1;
      users[idx].verificationCode = null;
      Database.users = users;

      setSession({ userId: users[idx].id, role: users[idx].role });
      return { success: true, id: users[idx].id, role: users[idx].role };
    }

    if (action === 'login') {
      const { email, password } = body;
      const user = Database.users.find(u => u.email.toLowerCase() === email.toLowerCase());
      if (!user || user.password !== password) throw new Error("Invalid login details");
      if (user.isVerified === 0) throw new Error("Account not verified. Please check your email for the code.");
      setSession({ userId: user.id, role: user.role });
      return { success: true, id: user.id, role: user.role };
    }

    if (action === 'logout') {
      setSession(null);
      return { success: true };
    }

    if (action === 'update_profile') {
      if (!session?.userId) throw new Error("Not authenticated");
      const users = Database.users;
      const idx = users.findIndex(u => u.id === session.userId);
      if (idx !== -1) {
        users[idx] = { ...users[idx], ...body };
        Database.users = users;
      }
      return { success: true };
    }
  }

  if (url.includes('settings.php')) {
    if (session?.role !== 'admin') throw new Error("Forbidden");

    if (action === 'save_school') {
      const { name, address, current_term, current_year } = body;
      Database.settings = { ...Database.settings, name, address, current_term, current_year };
      return { success: true };
    }

    if (action === 'save_session') {
      Database.settings = { ...Database.settings, current_year: body.session };
      return { success: true };
    }

    if (action === 'save_term') {
      Database.settings = { ...Database.settings, current_term: body.term };
      return { success: true };
    }

    if (action === 'add_activity') {
      const act = { id: uuid(), title: body.title, date: body.date };
      Database.activities = [...Database.activities, act];
      return { success: true, id: act.id };
    }
  }

  if (url.includes('students.php')) {
    if (session?.role !== 'admin') throw new Error("Forbidden");

    if (action === 'add') {
      const { firstName, middleName, lastName, email, phone, role, classLevel, password } = body;
      const fullName = [firstName, middleName, lastName].filter(Boolean).join(" ");
      const newUser = {
        id: uuid(),
        fullName,
        firstName,
        middleName,
        lastName,
        email: email.toLowerCase(),
        password: password || 'password123',
        phone: phone || '',
        role: role || 'student',
        classLevel: classLevel || '',
        isVerified: 1,
        createdAt: new Date().toISOString()
      };
      Database.users = [...Database.users, newUser];
      return { success: true, id: newUser.id };
    }

    if (action === 'edit') {
      const { id, firstName, middleName, lastName, email, phone, role, classLevel, password } = body;
      const users = Database.users;
      const idx = users.findIndex(u => u.id === id);
      if (idx !== -1) {
        const fullName = [firstName, middleName, lastName].filter(Boolean).join(" ");
        users[idx] = { ...users[idx], ...body, fullName };
        if (password) users[idx].password = password;
        Database.users = users;
      }
      return { success: true };
    }

    if (action === 'delete') {
      const { id } = body;
      Database.users = Database.users.filter(u => u.id !== id);
      return { success: true };
    }
  }

  if (url.includes('invoices.php')) {
    if (session?.role !== 'admin') throw new Error("Forbidden");

    if (action === 'open_or_create') {
      const { studentId, term, year } = body;
      const invoices = Database.invoices;
      const existing = invoices.find(i => i.studentId === studentId && i.term === term && i.year === year);

      if (existing) {
        existing.items = Database.invoice_items.filter(i => i.invoiceId === existing.id);
        return existing;
      }

      // Auto-increment INVOICE number logic
      const yearStr = new Date().getFullYear().toString();
      const prefix = `INV-${yearStr}-`;
      const yearInvoices = invoices.filter(i => i.invoiceNo && i.invoiceNo.startsWith(prefix));
      let nextNum = 1;
      if (yearInvoices.length > 0) {
        const last = yearInvoices.sort((a, b) => b.invoiceNo.localeCompare(a.invoiceNo))[0];
        nextNum = parseInt(last.invoiceNo.split('-')[2]) + 1;
      }
      const invoiceNo = prefix + String(nextNum).padStart(6, '0');

      const inv = {
        id: uuid(),
        invoiceNo,
        studentId,
        term,
        year,
        status: 'UNPAID',
        createdAt: new Date().toISOString()
      };
      Database.invoices = [...invoices, inv];
      return { ...inv, items: [] };
    }

    if (action === 'update_status') {
      const { id, status } = body;
      const invoices = Database.invoices;
      const idx = invoices.findIndex(i => i.id === id);
      if (idx !== -1) {
        invoices[idx].status = status;
        Database.invoices = invoices;
      }
      return { success: true };
    }

    if (action === 'add_item') {
      const { invoiceId, name, amount } = body;
      const item = { id: uuid(), invoiceId, name, amount: parseFloat(amount) };
      Database.invoice_items = [...Database.invoice_items, item];
      return { success: true, id: item.id };
    }

    if (action === 'remove_item') {
      const { itemId } = body;
      Database.invoice_items = Database.invoice_items.filter(i => i.id !== itemId);
      return { success: true };
    }

    if (action === 'summary') {
      const summary = {};
      Database.invoices.forEach(i => {
          const key = `${i.year} - ${i.term}`;
          if (!summary[key]) {
              summary[key] = { year: i.year, term: i.term, totalInvoiced: 0, totalPaid: 0 };
          }
          const items = Database.invoice_items.filter(it => it.invoiceId === i.id);
          const amt = items.reduce((sum, it) => sum + it.amount, 0);
          summary[key].totalInvoiced += amt;
          if (i.status === 'PAID') summary[key].totalPaid += amt;
      });
      return Object.values(summary).map(s => ({ ...s, balance: s.totalInvoiced - s.totalPaid }));
    }

    if (action === 'tracking') {
      const year = u.searchParams.get('year') || Database.settings.current_year || '2025/2026';
      const term = u.searchParams.get('term') || Database.settings.current_term || 'First Term';

      if (session?.role !== 'admin') throw new Error("Forbidden");
      return Database.users.filter(u => u.role === 'student').map(s => {
        const sInvoices = Database.invoices.filter(i => i.studentId === s.id && i.term === term && i.year === year);
        let totalInvoiced = 0;
        let totalPaid = 0;
        sInvoices.forEach(inv => {
            const iItems = Database.invoice_items.filter(it => it.invoiceId === inv.id);
            const sum = iItems.reduce((acc, curr) => acc + (parseFloat(curr.amount) || 0), 0);
            totalInvoiced += sum;
            if (inv.status === 'PAID') totalPaid += sum;
        });
        
        return {
          studentId: s.id,
          fullName: s.fullName,
          classLevel: s.classLevel,
          totalInvoiced,
          totalPaid,
          balance: totalInvoiced - totalPaid
        };
      });
    }
  }

  if (url.includes('fees.php')) {
    if (action === 'pay_fee') {
      const { items, term, year } = body;
      
      const receiptId = uuid();
      const receiptNo = "RCPT-" + new Date().getFullYear() + "-" + Math.floor(100000 + Math.random() * 900000);
      const createdAt = new Date().toISOString();
      
      Database.invoices.push({ id: receiptId, invoiceNo: receiptNo, studentId: session.userId, term, year, status: 'PAID', createdAt });
      const receiptItems = items.map(it => ({ id: uuid(), invoiceId: receiptId, name: it.description || it.name, amount: it.amount }));
      Database.invoice_items.push(...receiptItems);

      const existingIdx = Database.invoices.findIndex(i => i.studentId === session.userId && i.term === term && i.year === year && i.status === 'UNPAID');
      if (existingIdx !== -1) {
          const unpaidId = Database.invoices[existingIdx].id;
          items.forEach(paidItem => {
              Database.invoice_items = Database.invoice_items.filter(it => !(it.invoiceId === unpaidId && it.name === (paidItem.description || paidItem.name)));
          });
          const remaining = Database.invoice_items.filter(it => it.invoiceId === unpaidId);
          if (remaining.length === 0) {
              Database.invoices.splice(existingIdx, 1);
          }
      } else {
          const user = Database.users.find(u => u.id === session.userId);
          const userClass = user.classLevel || 'JS 1';
          const allPossible = Database.fees.filter(f => f.term === term && f.class_name === userClass);
          const remainingItems = allPossible.filter(p => !items.some(paid => (paid.description || paid.name) === p.description));
          
          if (remainingItems.length > 0) {
              const newUnpaidId = uuid();
              const newUnpaidNo = "INV-" + new Date().getFullYear() + "-" + Math.floor(100000 + Math.random() * 900000);
              Database.invoices.push({ id: newUnpaidId, invoiceNo: newUnpaidNo, studentId: session.userId, term, year, status: 'UNPAID', createdAt });
              Database.invoice_items.push(...remainingItems.map(it => ({ id: uuid(), invoiceId: newUnpaidId, name: it.description, amount: it.amount })));
          }
      }
      return { success: true, id: receiptId, invoiceNo: receiptNo };
    }
    
    if (session?.role !== 'admin') throw new Error("Forbidden");
    if (action === 'add') {
      const { term, year, description, amounts } = body;
      const fees = Database.fees;
      for (const [className, amount] of Object.entries(amounts)) {
          if (amount === '' || amount === null) continue;
          fees.push({ id: uuid(), term, year: year || '2025/2026', class_name: className, description, amount: parseFloat(amount) });
      }
      Database.fees = fees;
      return { success: true };
    }
    if (action === 'update') {
      const fees = Database.fees;
      const idx = fees.findIndex(f => f.id === body.id);
      if (idx !== -1) {
        fees[idx] = { ...fees[idx], ...body, amount: parseFloat(body.amount) };
        Database.fees = fees;
      }
      return { success: true };
    }
    if (action === 'delete') {
      Database.fees = Database.fees.filter(f => f.id !== body.id);
      return { success: true };
    }
  }

  throw new Error(`Mock POST not implemented: ${url}`);
}
