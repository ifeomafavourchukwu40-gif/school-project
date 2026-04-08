// Firebase configuration and initialization
// Firebase configuration and initialization for browser (CDN)
// Make sure to include Firebase CDN scripts in your HTML before this file:
// <script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-app-compat.js"></script>
// <script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-auth-compat.js"></script>
// <script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-firestore-compat.js"></script>
// <script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-storage-compat.js"></script>

const firebaseConfig = {
  apiKey: "AIzaSyADn3AuySlawF8TbUxyOCiaP8slT_F8wIE",
  authDomain: "school-336ae.firebaseapp.com",
  projectId: "school-336ae",
  storageBucket: "school-336ae.appspot.com",
  messagingSenderId: "389137856398",
  appId: "1:389137856398:web:faed54db8d597acd1996df",
  measurementId: "G-2SCD3EJY5T"
};

// Initialize Firebase (only if not already initialized)
if (!firebase.apps.length) {
  firebase.initializeApp(firebaseConfig);
}

const auth = firebase.auth();
const db = firebase.firestore();
const storage = firebase.storage();

export { auth, db, storage };