import struct, zlib, pathlib
root=pathlib.Path('.')
for file,side in [('favicon.png',128),('icons/icon-180.png',180),('icons/icon-192.png',192),('icons/icon-512.png',512),('icons/icon-maskable-512.png',512)]:
    data=(root/file).read_bytes()
    assert data[:8]==b'\x89PNG\r\n\x1a\n',file
    at=8; idat=b''
    while at<len(data):
        n=struct.unpack('>I',data[at:at+4])[0]
        kind=data[at+4:at+8]; value=data[at+8:at+8+n]
        assert zlib.crc32(kind+value)==struct.unpack('>I',data[at+8+n:at+12+n])[0],file
        if kind==b'IHDR':
            assert struct.unpack('>IIBBBBB',value)==(side,side,8,3,0,0,0),file
        if kind==b'IDAT': idat+=value
        at+=12+n
    raw=zlib.decompress(idat)
    assert len(raw)==side*(side+1) and len(set(raw))>20,file
for f in ['icons/urovia-mark.svg','icons/urovia-mark-mono.svg','icons/uvoria-app.svg','icons/uvoria-maskable.svg']:
    import xml.etree.ElementTree as ET
    ET.parse(f)
print('UROVIA brand: valid SVG and five decoded PNG icons')
