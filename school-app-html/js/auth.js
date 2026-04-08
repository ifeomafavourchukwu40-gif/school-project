
import { setSession } from "../assets/js/storage.js";
import { auth, db } from "../assets/js/firebase.js";

// Signup with Firebase Auth and Firestore
export async function signup(payload) {
  const { firstName, middleName, lastName, email, password, role } = payload;
  // Create user in Firebase Auth
  const userCredential = await auth.createUserWithEmailAndPassword(email, password);
  const user = userCredential.user;
  // Send email verification
  await user.sendEmailVerification();
  // Add user profile to Firestore
  await db.collection("users").doc(user.uid).set({
    uid: user.uid,
    name: `${firstName} ${middleName || ''} ${lastName}`.replace(/\s+/g, ' ').trim(),
    email,
    phone: payload.phone || '',
    role: role || 'student',
    verified: false,
    createdAt: new Date().toISOString()
  });
  return { success: true, message: "Verification email sent. Please check your inbox." };
}

// Verify email (Firebase handles this via email link)
export async function verify() {
  // Firebase handles verification via email link, so just check if verified
  const user = auth.currentUser;
  await user.reload();
  if (user.emailVerified) {
    // Update Firestore user profile
    await db.collection("users").doc(user.uid).update({ verified: true });
    setSession({ userId: user.uid, role: (await db.collection("users").doc(user.uid).get()).data().role });
    return { success: true };
  } else {
    throw new Error("Email not verified yet. Please check your inbox.");
  }
}

export async function login({ email, password }) {
  console.log("Login attempt for:", email);
  // Sign in with Firebase Auth
  const userCredential = await auth.signInWithEmailAndPassword(email, password);
  const user = userCredential.user;
  if (!user.emailVerified) {
    throw new Error("Email not verified. Please check your inbox and verify your email.");
  }
  // Get user role from Firestore
  const userDoc = await db.collection("users").doc(user.uid).get();
  if (!userDoc.exists) {
    throw new Error("User profile not found in database.");
  }
  const userData = userDoc.data();
  setSession({ userId: user.uid, role: userData.role });
  return true;
}

export async function resetPassword({ email, newPassword }) {
  throw new Error("Not implemented in PHP backend yet.");
}