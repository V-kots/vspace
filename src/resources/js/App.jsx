import { BrowserRouter, Route, Routes } from 'react-router-dom';
import Landing from './pages/Landing';
import Register from './pages/Register';
import Login from './pages/Login';
import Cabinet from './pages/Cabinet';
import Profile from './pages/Profile';
import Search from './pages/Search';
import Friends from './pages/Friends';
import Posts from './pages/Posts';

export default function App() {
    return (
        <BrowserRouter>
            <Routes>
                <Route path="/" element={<Landing />} />
                <Route path="/register" element={<Register />} />
                <Route path="/login" element={<Login />} />
                <Route path="/cabinet" element={<Cabinet />} />
                <Route path="/search" element={<Search />} />
                <Route path="/friends" element={<Friends />} />
                <Route path="/posts" element={<Posts />} />
                <Route path="/profile/:id" element={<Profile />} />
            </Routes>
        </BrowserRouter>
    );
}
