const net = require('net');

const server = net.createServer((socket) => {
  socket.on('data', (data) => {
    data.toString();
    socket.write('HTTP/1.1 200 OK\r\n');
    socket.write('Connection: close\r\n');
    socket.write('Content-Length: 13\r\n');
    socket.write('\r\n');
    socket.write('Hello, world!');
    socket.end(); // Close the connection after sending the response
  });
  socket.on('error', (err) => {
    if (err.code === 'ECONNRESET') {
      // Handle ECONNRESET gracefully (e.g., log it)
      console.error('Client connection reset:', err);
    } else {
      // Handle other errors
      console.error('Socket error:', err);
    }
  });
});

server.listen(23432, '0.0.0.0');
