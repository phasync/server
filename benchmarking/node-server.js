// Minimal keep-alive HTTP/1.1 responder on the net module (not the http module), doing the
// same work as tcpserver.php: read until a blank line, write a canned response.
// Usage: node node-server.js [port]

const net = require('net');

const port = Number(process.argv[2]) || 8081;
const body = 'Hello World\n';
const resp = `HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: ${body.length}\r\nConnection: keep-alive\r\n\r\n${body}`;

const server = net.createServer({ noDelay: true }, (sock) => {
    let buf = '';
    sock.on('data', (d) => {
        buf += d.toString('latin1');
        let n = 0;
        let p;
        while ((p = buf.indexOf('\r\n\r\n')) !== -1) {
            buf = buf.slice(p + 4);
            n++;
        }
        if (n > 0) {
            sock.write(n === 1 ? resp : resp.repeat(n));
        }
    });
    sock.on('error', () => {});
});

server.listen({ port, backlog: 65535 }, () => console.log(`node listening on ${port}`));
