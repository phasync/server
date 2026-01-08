import cluster from 'cluster';
import net from 'net';
import os from 'os';

const port = process.argv[2] || 8080;
const cores = os.cpus().length;

if (cluster.isPrimary) {
    console.log(`Listening on http://0.0.0.0:${port} (Node.js, ${cores} workers)`);

    for (let i = 0; i < cores; i++) {
        cluster.fork();
    }
} else {
    const response = "HTTP/1.0 200 OK\r\nContent-Length: 12\r\nConnection: close\r\n\r\nHello World\n";

    const server = net.createServer((socket) => {
        socket.once('data', () => {
            socket.end(response);
        });
    });

    server.listen(port, '0.0.0.0');
}
